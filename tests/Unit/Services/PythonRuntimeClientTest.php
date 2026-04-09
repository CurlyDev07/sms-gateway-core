<?php

namespace Tests\Unit\Services;

use App\Services\PythonRuntimeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PythonRuntimeClientTest extends TestCase
{
    private PythonRuntimeClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_health_path', '/health');
        config()->set('sms.python_api_discover_path', '/modems/discover');
        config()->set('sms.python_api_timeout_seconds', 35);
        config()->set('sms.python_api_token', '');

        $this->client = app(PythonRuntimeClient::class);
    }

    public function test_health_returns_success_payload_when_python_is_reachable(): void
    {
        Http::fake([
            'http://python-engine.test/health' => Http::response([
                'status' => 'ok',
                'uptime_seconds' => 1234,
            ], 200),
        ]);

        $result = $this->client->health();

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertNull($result['error']);
        $this->assertSame('ok', $result['data']['status']);
    }

    public function test_discover_extracts_modems_from_standard_payload(): void
    {
        Http::fake([
            'http://python-engine.test/modems/discover' => Http::response([
                'ok' => true,
                'modems' => [
                    ['device_id' => 'mdm-1', 'sim_id' => '515031234567890', 'port' => '/dev/ttyUSB0'],
                    ['device_id' => 'mdm-2', 'sim_id' => '515039999999999', 'port' => '/dev/ttyUSB1'],
                ],
            ], 200),
        ]);

        $result = $this->client->discover();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, count($result['modems']));
        $this->assertSame('mdm-1', $result['modems'][0]['device_id']);
        $this->assertSame('515031234567890', $result['modems'][0]['sim_id']);
    }

    public function test_client_fails_fast_when_python_api_url_is_missing(): void
    {
        Http::fake();
        config()->set('sms.python_api_url', '');

        $result = $this->client->health();

        $this->assertFalse($result['ok']);
        $this->assertNull($result['status']);
        $this->assertSame('python_api_url_not_configured', $result['error']);
        Http::assertNothingSent();
    }

    public function test_client_returns_structured_error_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $result = $this->client->discover();

        $this->assertFalse($result['ok']);
        $this->assertNull($result['status']);
        $this->assertSame('connection_failed', $result['error']);
        $this->assertSame([], $result['modems']);
    }

    public function test_client_sends_gateway_token_header_when_configured(): void
    {
        config()->set('sms.python_api_token', 'test-gateway-token');

        Http::fake([
            'http://python-engine.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = $this->client->health();

        $this->assertTrue($result['ok']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://python-engine.test/health'
                && $request->hasHeader('X-Gateway-Token')
                && $request->header('X-Gateway-Token')[0] === 'test-gateway-token';
        });
    }
}
