<?php

namespace Tests\Feature\Platform;

use App\Models\ApiClient;
use App\Models\Company;
use App\Models\CompanyChatAppIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChatAppTenantRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.chat_app.platform_key', 'platform-key');
        config()->set('services.chat_app.platform_secret', 'platform-secret');
        config()->set('services.chat_app.platform_timestamp_tolerance_seconds', 300);
    }

    /** @test */
    public function it_provisions_gateway_company_credentials_and_chatapp_integration(): void
    {
        $payload = $this->tenantPayload();

        $response = $this->signedJson('POST', '/api/platform/chatapp/tenants', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('created', true)
            ->assertJsonPath('gateway_company.code', 'tenant-company-inc')
            ->assertJsonPath('registration.chatapp_company_id', '123')
            ->assertJsonPath('registration.chatapp_tenant_key', '669');

        $userId = (string) $response->json('outbound_credentials.user_id');
        $apiKey = (string) $response->json('outbound_credentials.api_key');
        $inboundSecret = (string) $response->json('inbound_credentials.secret');

        $this->assertNotSame('', $userId);
        $this->assertNotSame('', $apiKey);
        $this->assertNotSame('', $inboundSecret);

        $company = Company::query()->where('code', 'tenant-company-inc')->firstOrFail();
        $client = ApiClient::query()->where('api_key', $userId)->firstOrFail();
        $integration = CompanyChatAppIntegration::query()->where('chatapp_company_id', '123')->firstOrFail();

        $this->assertSame((int) $company->id, (int) $client->company_id);
        $this->assertTrue(Hash::check($apiKey, (string) $client->api_secret));
        $this->assertSame((int) $company->id, (int) $integration->company_id);
        $this->assertSame('669', $integration->chatapp_tenant_key);
        $this->assertSame($inboundSecret, $integration->inboundSecret());
    }

    /** @test */
    public function it_is_idempotent_and_does_not_return_secrets_again(): void
    {
        $payload = $this->tenantPayload();

        $this->signedJson('POST', '/api/platform/chatapp/tenants', $payload)
            ->assertCreated()
            ->assertJsonPath('created', true);

        $response = $this->signedJson('POST', '/api/platform/chatapp/tenants', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('created', false);

        $this->assertArrayNotHasKey('outbound_credentials', $response->json());
        $this->assertArrayNotHasKey('inbound_credentials', $response->json());

        $this->assertSame(1, CompanyChatAppIntegration::query()->where('chatapp_company_id', '123')->count());
        $this->assertSame(1, ApiClient::query()->count());
    }

    /** @test */
    public function it_rejects_unsigned_platform_requests(): void
    {
        $this->postJson('/api/platform/chatapp/tenants', $this->tenantPayload())
            ->assertUnauthorized()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'unauthorized');
    }

    /** @test */
    public function it_rotates_outbound_credentials_and_disables_old_chatapp_client(): void
    {
        $first = $this->signedJson('POST', '/api/platform/chatapp/tenants', $this->tenantPayload())
            ->assertCreated();

        $oldUserId = (string) $first->json('outbound_credentials.user_id');

        $response = $this->signedJson('POST', '/api/platform/chatapp/tenants/123/rotate-outbound');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true);

        $newUserId = (string) $response->json('outbound_credentials.user_id');
        $newApiKey = (string) $response->json('outbound_credentials.api_key');

        $this->assertNotSame($oldUserId, $newUserId);
        $this->assertSame('disabled', ApiClient::query()->where('api_key', $oldUserId)->value('status'));
        $this->assertTrue(Hash::check($newApiKey, (string) ApiClient::query()->where('api_key', $newUserId)->value('api_secret')));
    }

    /** @test */
    public function it_rotates_inbound_signing_secret(): void
    {
        $first = $this->signedJson('POST', '/api/platform/chatapp/tenants', $this->tenantPayload())
            ->assertCreated();

        $oldSecret = (string) $first->json('inbound_credentials.secret');

        $response = $this->signedJson('POST', '/api/platform/chatapp/tenants/123/rotate-inbound');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('inbound_credentials.tenant_key', '669');

        $newSecret = (string) $response->json('inbound_credentials.secret');

        $this->assertNotSame($oldSecret, $newSecret);
        $this->assertSame($newSecret, CompanyChatAppIntegration::query()->firstOrFail()->inboundSecret());
    }

    /** @test */
    public function it_can_disable_gateway_tenant_and_api_clients(): void
    {
        $this->signedJson('POST', '/api/platform/chatapp/tenants', $this->tenantPayload())
            ->assertCreated();

        $this->signedJson('PATCH', '/api/platform/chatapp/tenants/123/status', [
            'status' => 'disabled',
        ])
            ->assertOk()
            ->assertJsonPath('gateway_company.status', 'disabled')
            ->assertJsonPath('registration.status', 'disabled');

        $this->assertSame('disabled', Company::query()->firstOrFail()->status);
        $this->assertSame(0, ApiClient::query()->where('status', 'active')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantPayload(): array
    {
        return [
            'chatapp_company_id' => '123',
            'chatapp_company_uuid' => '11111111-1111-1111-1111-111111111111',
            'company_name' => 'Tenant Company Inc.',
            'company_code' => 'tenant-company-inc',
            'timezone' => 'Asia/Manila',
            'chatapp_inbound_url' => 'https://chat.example.com/api/infotxt/inbox',
            'chatapp_tenant_key' => '669',
        ];
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array<string, mixed> $payload
     * @return \Illuminate\Testing\TestResponse
     */
    private function signedJson(string $method, string $uri, array $payload = [])
    {
        $body = json_encode($payload);
        $timestamp = (string) time();

        return $this->withHeaders([
            'X-Platform-Key' => 'platform-key',
            'X-Platform-Timestamp' => $timestamp,
            'X-Platform-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'platform-secret'),
        ])->json($method, $uri, $payload);
    }
}
