<?php

namespace Tests\Feature\Http;

use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class GatewayOutboundControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function active_sim_returns_200_and_saves_pending_message(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $this->createAssignment($company, $sim, '09171234567');

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/send', [
                'customer_phone' => '09171234567',
                'message' => 'Hello active',
                'message_type' => 'CHAT',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'status' => 'queued',
                'queued' => true,
                'sim_id' => $sim->id,
            ]);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09171234567',
            'status' => 'pending',
            'message_type' => 'CHAT',
        ]);
    }

    /** @test */
    public function paused_sim_returns_202_and_saves_non_enqueued_message(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'paused',
            'status' => 'active',
        ]);

        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $this->createAssignment($company, $sim, '09171234568');

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/send', [
                'customer_phone' => '09171234568',
                'message' => 'Hello paused',
                'message_type' => 'AUTO_REPLY',
            ]);

        $response->assertStatus(202)
            ->assertJson([
                'ok' => true,
                'status' => 'accepted',
                'queued' => false,
                'sim_id' => $sim->id,
            ]);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09171234568',
            'status' => 'queued',
            'message_type' => 'AUTO_REPLY',
        ]);
    }

    /** @test */
    public function blocked_sim_returns_503_and_does_not_save_message(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'blocked',
            'status' => 'active',
        ]);

        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $this->createAssignment($company, $sim, '09171234569');

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/send', [
                'customer_phone' => '09171234569',
                'message' => 'Hello blocked',
                'message_type' => 'FOLLOW_UP',
            ]);

        $response->assertStatus(503)
            ->assertJson([
                'ok' => false,
                'error' => 'sim_blocked',
            ]);

        $this->assertDatabaseMissing('outbound_messages', [
            'company_id' => $company->id,
            'customer_phone' => '09171234569',
            'message' => 'Hello blocked',
        ]);
    }

    /** @test */
    public function outbound_intake_requires_api_client_authentication(): void
    {
        $response = $this->postJson('/api/messages/send', [
            'customer_phone' => '09170000001',
            'message' => 'No auth',
            'message_type' => 'CHAT',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'ok' => false,
                'error' => 'unauthorized',
            ]);
    }

    /** @test */
    public function tenant_mismatch_in_request_body_is_rejected_before_intake(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA']);
        $companyB = $this->createCompany(['code' => 'CMPB']);

        $sim = $this->createSim($companyA, [
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        [$apiClient, $plainSecret] = $this->createApiClient($companyA);

        $this->createAssignment($companyA, $sim, '09170000002');

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/send', [
                'company_id' => $companyB->id,
                'customer_phone' => '09170000002',
                'message' => 'Mismatch attempt',
                'message_type' => 'BLAST',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'error' => 'forbidden',
            ]);

        $this->assertSame(0, OutboundMessage::query()->count());
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $apiKey, string $apiSecret): array
    {
        return [
            'X-API-KEY' => $apiKey,
            'X-API-SECRET' => $apiSecret,
        ];
    }
}
