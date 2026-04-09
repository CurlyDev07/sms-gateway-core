<?php

namespace Tests\Unit\Services\Sms;

use App\Services\Sms\PythonApiSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class PythonApiSmsSenderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private PythonApiSmsSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sms.python_api_url', 'http://python-engine.test');
        $this->sender = app(PythonApiSmsSender::class);
    }

    /** @test */
    public function it_fails_early_when_sim_imsi_is_missing(): void
    {
        Http::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => null]);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertFalse($result->success);
        $this->assertSame('SIM_IMSI_MISSING', $result->error);
        $this->assertSame('gateway', $result->errorLayer);
        Http::assertNothingSent();
    }

    /** @test */
    public function it_fails_early_when_python_api_url_is_empty(): void
    {
        Http::fake();
        config()->set('sms.python_api_url', '');

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertFalse($result->success);
        $this->assertSame('UNKNOWN_ERROR', $result->error);
        $this->assertSame('gateway', $result->errorLayer);
        Http::assertNothingSent();
    }

    /** @test */
    public function it_returns_http_error_for_non_2xx_python_response(): void
    {
        Http::fake([
            'http://python-engine.test/send' => Http::response([
                'success' => false,
                'error' => 'MODEM_UNAVAILABLE',
                'raw' => [
                    'error_layer' => 'transport',
                ],
            ], 500),
        ]);

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertFalse($result->success);
        $this->assertSame('HTTP_ERROR', $result->error);
        $this->assertSame('transport', $result->errorLayer);
    }

    /** @test */
    public function it_returns_transport_error_layer_when_connection_to_python_fails(): void
    {
        // ConnectionException = Laravel cannot reach Python (TCP failure, Python is down).
        // This is NOT a carrier rejection — it is a transport failure and must retry.
        // errorLayer must be 'transport', not 'network', so the worker does not permanently fail the message.
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertFalse($result->success);
        $this->assertSame('RUNTIME_UNREACHABLE', $result->error);
        $this->assertSame('transport', $result->errorLayer);
    }

    /** @test */
    public function it_returns_runtime_timeout_when_python_send_times_out(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertFalse($result->success);
        $this->assertSame('RUNTIME_TIMEOUT', $result->error);
        $this->assertSame('transport', $result->errorLayer);
    }

    /** @test */
    public function it_returns_success_when_python_response_success_is_true(): void
    {
        Http::fake([
            'http://python-engine.test/send' => Http::response([
                'success' => true,
                'message_id' => 'py-msg-123',
                'raw' => [
                    'provider' => 'modem',
                ],
            ], 200),
        ]);

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('py-msg-123', $result->providerMessageId);
        $this->assertNull($result->error);
        $this->assertNull($result->errorLayer);
        $this->assertSame(['provider' => 'modem'], $result->raw);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'http://python-engine.test/send'
                && ($data['sim_id'] ?? null) === '515031234567890';
        });
    }

    /** @test */
    public function it_sends_auth_header_when_token_is_configured(): void
    {
        config()->set('sms.python_api_token', 'test-gateway-secret');

        Http::fake([
            'http://python-engine.test/send' => Http::response([
                'success' => true,
                'message_id' => 'py-msg-456',
                'raw' => [],
            ], 200),
        ]);

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertTrue($result->success);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('X-Gateway-Token')
                && $request->header('X-Gateway-Token')[0] === 'test-gateway-secret';
        });
    }

    /** @test */
    public function it_does_not_send_auth_header_when_token_is_not_configured(): void
    {
        config()->set('sms.python_api_token', '');

        Http::fake([
            'http://python-engine.test/send' => Http::response([
                'success' => true,
                'message_id' => 'py-msg-789',
                'raw' => [],
            ], 200),
        ]);

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertTrue($result->success);

        Http::assertSent(function (Request $request) {
            return !$request->hasHeader('X-Gateway-Token');
        });
    }

    /** @test */
    public function it_returns_python_execution_failure_using_top_level_error_and_raw_error_layer(): void
    {
        Http::fake([
            'http://python-engine.test/send' => Http::response([
                'success' => false,
                'error' => 'MODEM_TIMEOUT',
                'raw' => [
                    'error_layer' => 'modem',
                    'detail' => 'send timeout',
                ],
            ], 200),
        ]);

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertFalse($result->success);
        $this->assertSame('MODEM_TIMEOUT', $result->error);
        $this->assertSame('modem', $result->errorLayer);
        $this->assertSame([
            'error_layer' => 'modem',
            'detail' => 'send timeout',
        ], $result->raw);
    }

    /** @test */
    public function it_returns_invalid_response_when_python_success_flag_is_missing(): void
    {
        Http::fake([
            'http://python-engine.test/send' => Http::response([
                'status' => 'ok',
                'message_id' => 'py-msg-666',
            ], 200),
        ]);

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515031234567890']);

        $result = $this->sender->send((int) $sim->id, '09171234567', 'Hello');

        $this->assertFalse($result->success);
        $this->assertSame('INVALID_RESPONSE', $result->error);
        $this->assertSame('python_api', $result->errorLayer);
    }
}
