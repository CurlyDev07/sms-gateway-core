<?php

namespace Tests\Unit\Services;

use App\Models\InboundMessage;
use App\Models\CompanyChatAppIntegration;
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
        config()->set('services.chat_app.tenant_key', null);
        config()->set('services.chat_app.inbound_secret', null);

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
            $capturedMobile = $this->formValue($request, 'MOBILE');
            return Http::response(['ok' => true], 200);
        });

        $message = $this->createInboundMessage('+639278986797');

        $result = $this->service->relay($message);

        $this->assertTrue($result);
        $this->assertSame('09278986797', $capturedMobile);
    }

    /** @test */
    public function it_uses_inbound_uuid_for_relay_id_to_avoid_duplicate_id_reuse(): void
    {
        $capturedId = null;

        Http::fake(function (Request $request) use (&$capturedId) {
            $capturedId = $this->formValue($request, 'ID');
            return Http::response(['ok' => true], 200);
        });

        $message = $this->createInboundMessage('+639278986797');

        $result = $this->service->relay($message);

        $this->assertTrue($result);
        $this->assertSame('GW-IN-'.$message->uuid, $capturedId);
    }

    /** @test */
    public function it_sends_tenant_key_and_hmac_signature_headers_when_configured(): void
    {
        config()->set('services.chat_app.tenant_key', '669');
        config()->set('services.chat_app.inbound_secret', 'postman-dev-secret');

        $capturedBody = null;
        $capturedTimestamp = null;
        $capturedSignature = null;

        Http::fake(function (Request $request) use (&$capturedBody, &$capturedTimestamp, &$capturedSignature) {
            $capturedBody = $request->body();
            $capturedTimestamp = $request->header('X-Gateway-Timestamp')[0] ?? null;
            $capturedSignature = $request->header('X-Gateway-Signature')[0] ?? null;

            return Http::response(['ok' => true], 200);
        });

        $message = $this->createInboundMessage('+639278986797');

        $result = $this->service->relay($message);

        $this->assertTrue($result);
        $this->assertIsString($capturedTimestamp);
        $this->assertMatchesRegularExpression('/^\d+$/', $capturedTimestamp);
        $this->assertSame('669', $this->formValueFromBody((string) $capturedBody, 'TENANT_KEY'));
        $this->assertSame(
            hash_hmac('sha256', $capturedTimestamp.'.'.$capturedBody, 'postman-dev-secret'),
            $capturedSignature
        );
    }

    /** @test */
    public function it_prefers_company_chat_app_integration_settings_for_signed_inbound_relay(): void
    {
        config()->set('services.chat_app.inbound_url', 'http://fallback.test/api/infotxt/inbox');
        config()->set('services.chat_app.tenant_key', 'fallback-tenant');
        config()->set('services.chat_app.inbound_secret', 'fallback-secret');

        $capturedBody = null;
        $capturedTimestamp = null;
        $capturedSignature = null;
        $capturedKeyId = null;
        $capturedUrl = null;

        Http::fake(function (Request $request) use (&$capturedBody, &$capturedTimestamp, &$capturedSignature, &$capturedKeyId, &$capturedUrl) {
            $capturedUrl = (string) $request->url();
            $capturedBody = $request->body();
            $capturedTimestamp = $request->header('X-Gateway-Timestamp')[0] ?? null;
            $capturedSignature = $request->header('X-Gateway-Signature')[0] ?? null;
            $capturedKeyId = $request->header('X-Gateway-Key-Id')[0] ?? null;

            return Http::response(['ok' => true], 200);
        });

        $message = $this->createInboundMessage('+639278986797');
        $integration = new CompanyChatAppIntegration([
            'company_id' => $message->company_id,
            'chatapp_company_id' => '123',
            'chatapp_inbound_url' => 'http://company-chatapp.test/api/infotxt/inbox',
            'chatapp_tenant_key' => 'tenant-company-123',
            'status' => 'active',
        ]);
        $integration->setInboundSecret('company-secret');
        $integration->save();

        $result = $this->service->relay($message);

        $this->assertTrue($result);
        $this->assertSame('http://company-chatapp.test/api/infotxt/inbox', $capturedUrl);
        $this->assertSame('tenant-company-123', $this->formValueFromBody((string) $capturedBody, 'TENANT_KEY'));
        $this->assertSame('tenant-company-123', $capturedKeyId);
        $this->assertSame(
            hash_hmac('sha256', $capturedTimestamp.'.'.$capturedBody, 'company-secret'),
            $capturedSignature
        );
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

    private function formValue(Request $request, string $key): ?string
    {
        return $this->formValueFromBody($request->body(), $key);
    }

    private function formValueFromBody(string $body, string $key): ?string
    {
        parse_str($body, $values);

        return isset($values[$key]) ? (string) $values[$key] : null;
    }
}
