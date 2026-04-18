<?php

namespace Tests\Unit\Services;

use App\Models\InboundMessage;
use App\Services\InboundRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class InboundRelayServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private InboundRelayService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.chat_app.inbound_url', 'http://chatapp.test/api/infotxt/inbox');
        config()->set('services.chat_app.timeout', 10);

        $this->service = app(InboundRelayService::class);
    }

    /** @test */
    public function it_marks_relay_as_success_when_chat_app_acknowledges_ok_true(): void
    {
        Http::fake([
            'http://chatapp.test/api/infotxt/inbox' => Http::response([
                'ok' => true,
            ], 200),
        ]);

        $message = $this->createInboundMessage('+639278986797');

        $result = $this->service->relay($message);

        $this->assertTrue($result);

        $message->refresh();
        $this->assertTrue((bool) $message->relayed_to_chat_app);
        $this->assertSame('success', $message->relay_status);
        $this->assertNull($message->relay_error);
        $this->assertNotNull($message->relayed_at);
    }

    /** @test */
    public function it_marks_relay_as_failed_when_chat_app_returns_ok_false_even_if_http_200(): void
    {
        Http::fake([
            'http://chatapp.test/api/infotxt/inbox' => Http::response([
                'ok' => false,
                'error' => 'validation_failed',
            ], 200),
        ]);

        $message = $this->createInboundMessage('+639278986797');

        $result = $this->service->relay($message);

        $this->assertFalse($result);

        $message->refresh();
        $this->assertFalse((bool) $message->relayed_to_chat_app);
        $this->assertSame('failed', $message->relay_status);
        $this->assertNotNull($message->relay_error);
        $this->assertStringContainsString('validation_failed', (string) $message->relay_error);
    }

    /** @test */
    public function it_normalizes_plus63_mobile_to_local_09_before_relaying_to_chat_app(): void
    {
        $capturedMobile = null;

        Http::fake(function (Request $request) use (&$capturedMobile) {
            $capturedMobile = $request['MOBILE'];
            return Http::response(['ok' => true], 200);
        });

        $message = $this->createInboundMessage('+639278986797');

        $result = $this->service->relay($message);

        $this->assertTrue($result);
        $this->assertSame('09278986797', $capturedMobile);
    }

    protected function createInboundMessage(string $customerPhone): InboundMessage
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515039219149367']);

        return InboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '515039219149367',
            'customer_phone' => $customerPhone,
            'message' => 'Inbound hello',
            'received_at' => now(),
            'idempotency_key' => 'inbound-relay-test-'.uniqid('', false),
            'relay_status' => 'pending',
            'relayed_to_chat_app' => false,
        ]);
    }
}

