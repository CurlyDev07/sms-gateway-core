<?php

namespace Tests\Unit\Services;

use App\Jobs\RelayOutboundStatusJob;
use App\Models\CompanyChatAppIntegration;
use App\Models\OutboundMessage;
use App\Services\OutboundStatusRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class OutboundStatusRelayServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private OutboundStatusRelayService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.chat_app.delivery_status_url', null);
        config()->set('services.chat_app.tenant_key', null);
        config()->set('services.chat_app.inbound_secret', null);

        $this->service = app(OutboundStatusRelayService::class);
    }

    /** @test */
    public function it_relays_signed_delivery_status_using_company_integration_settings(): void
    {
        $message = $this->createOutboundMessage([
            'status' => 'sent',
            'retry_count' => 0,
            'failure_reason' => null,
        ]);

        $integration = new CompanyChatAppIntegration([
            'company_id' => $message->company_id,
            'chatapp_company_id' => '123',
            'chatapp_inbound_url' => 'http://chatapp.test/api/infotxt/inbox',
            'chatapp_delivery_status_url' => 'http://chatapp.test/api/gateway/delivery-status',
            'chatapp_tenant_key' => 'tenant-company-123',
            'status' => 'active',
        ]);
        $integration->setInboundSecret('company-secret');
        $integration->save();

        $capturedUrl = null;
        $capturedBody = null;
        $capturedTimestamp = null;
        $capturedSignature = null;
        $capturedKeyId = null;

        Http::fake(function (Request $request) use (&$capturedUrl, &$capturedBody, &$capturedTimestamp, &$capturedSignature, &$capturedKeyId) {
            $capturedUrl = (string) $request->url();
            $capturedBody = $request->body();
            $capturedTimestamp = $request->header('X-Gateway-Timestamp')[0] ?? null;
            $capturedSignature = $request->header('X-Gateway-Signature')[0] ?? null;
            $capturedKeyId = $request->header('X-Gateway-Key-Id')[0] ?? null;

            return Http::response(['ok' => true], 200);
        });

        $result = $this->service->relay($message->fresh(['company.chatAppIntegration', 'sim']), 'evt-abc-123', 'queued');

        $this->assertTrue($result);
        $this->assertSame('http://chatapp.test/api/gateway/delivery-status', $capturedUrl);
        $this->assertSame('tenant-company-123', $capturedKeyId);
        $this->assertSame('tenant-company-123', $this->formValueFromBody((string) $capturedBody, 'TENANT_KEY'));
        $this->assertSame((string) $message->id, $this->formValueFromBody((string) $capturedBody, 'SMSID'));
        $this->assertSame('sent', $this->formValueFromBody((string) $capturedBody, 'STATUS'));
        $this->assertSame('evt-abc-123', $this->formValueFromBody((string) $capturedBody, 'EVENT_ID'));
        $this->assertSame(
            hash_hmac('sha256', $capturedTimestamp.'.'.$capturedBody, 'company-secret'),
            $capturedSignature
        );
    }

    /** @test */
    public function it_queues_status_callback_job_for_terminal_status_when_settings_exist(): void
    {
        config()->set('services.chat_app.delivery_status_url', 'http://chatapp.test/api/gateway/delivery-status');
        config()->set('services.chat_app.tenant_key', 'tenant-dev');
        config()->set('services.chat_app.inbound_secret', 'dev-secret');

        Queue::fake();

        $message = $this->createOutboundMessage([
            'status' => 'sent',
        ]);

        $queued = $this->service->queueIfEligible($message, 'queued');

        $this->assertTrue($queued);
        Queue::assertPushed(RelayOutboundStatusJob::class, 1);
    }

    /** @test */
    public function it_does_not_queue_status_callback_for_non_terminal_status(): void
    {
        config()->set('services.chat_app.delivery_status_url', 'http://chatapp.test/api/gateway/delivery-status');
        config()->set('services.chat_app.tenant_key', 'tenant-dev');
        config()->set('services.chat_app.inbound_secret', 'dev-secret');

        Queue::fake();

        $message = $this->createOutboundMessage([
            'status' => 'queued',
        ]);

        $queued = $this->service->queueIfEligible($message, 'pending');

        $this->assertFalse($queued);
        Queue::assertNothingPushed();
    }

    protected function createOutboundMessage(array $overrides = []): OutboundMessage
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['imsi' => '515039219149367']);

        return OutboundMessage::query()->create(array_merge([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09550090156',
            'message' => 'Hello callback',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'pending',
            'retry_count' => 0,
            'metadata' => [
                'source' => 'infotxt_outbound_compat',
                'python_runtime' => [
                    'raw' => [
                        'sim_id' => '515039219149367',
                    ],
                ],
            ],
        ], $overrides));
    }

    private function formValueFromBody(string $body, string $key): ?string
    {
        parse_str($body, $values);

        return isset($values[$key]) ? (string) $values[$key] : null;
    }
}

