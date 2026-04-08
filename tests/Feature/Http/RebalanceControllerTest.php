<?php

namespace Tests\Feature\Http;

use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class RebalanceControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_rebalances_only_safe_assignments_and_their_pending_or_queued_messages(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company);

        $safeAssignment = $this->createAssignment($company, $fromSim, '09170000001', [
            'status' => 'active',
            'safe_to_migrate' => true,
            'migration_locked' => false,
        ]);

        $unsafeAssignment = $this->createAssignment($company, $fromSim, '09170000002', [
            'status' => 'active',
            'safe_to_migrate' => false,
            'migration_locked' => false,
        ]);

        $lockedAssignment = $this->createAssignment($company, $fromSim, '09170000003', [
            'status' => 'active',
            'safe_to_migrate' => true,
            'migration_locked' => true,
        ]);

        $movedPending = $this->createOutboundMessage($company->id, $fromSim->id, '09170000001', 'pending');
        $movedQueued = $this->createOutboundMessage($company->id, $fromSim->id, '09170000001', 'queued');
        $notMovedSending = $this->createOutboundMessage($company->id, $fromSim->id, '09170000001', 'sending');
        $notMovedUnsafe = $this->createOutboundMessage($company->id, $fromSim->id, '09170000002', 'pending');
        $notMovedLocked = $this->createOutboundMessage($company->id, $fromSim->id, '09170000003', 'pending');

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/rebalance', [
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.from_sim_id', $fromSim->id)
            ->assertJsonPath('result.to_sim_id', $toSim->id)
            ->assertJsonPath('result.assignments_moved', 1)
            ->assertJsonPath('result.messages_moved', 2);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'id' => $safeAssignment->id,
            'sim_id' => $toSim->id,
        ]);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'id' => $unsafeAssignment->id,
            'sim_id' => $fromSim->id,
        ]);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'id' => $lockedAssignment->id,
            'sim_id' => $fromSim->id,
        ]);

        $this->assertDatabaseHas('outbound_messages', [
            'id' => $movedPending->id,
            'sim_id' => $toSim->id,
        ]);

        $this->assertDatabaseHas('outbound_messages', [
            'id' => $movedQueued->id,
            'sim_id' => $toSim->id,
        ]);

        $this->assertDatabaseHas('outbound_messages', [
            'id' => $notMovedSending->id,
            'sim_id' => $fromSim->id,
        ]);

        $this->assertDatabaseHas('outbound_messages', [
            'id' => $notMovedUnsafe->id,
            'sim_id' => $fromSim->id,
        ]);

        $this->assertDatabaseHas('outbound_messages', [
            'id' => $notMovedLocked->id,
            'sim_id' => $fromSim->id,
        ]);
    }

    /** @test */
    public function it_returns_zero_counts_when_no_assignments_are_eligible_for_rebalance(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company);

        $this->createAssignment($company, $fromSim, '09170000100', [
            'status' => 'active',
            'safe_to_migrate' => false,
            'migration_locked' => false,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/rebalance', [
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.assignments_moved', 0)
            ->assertJsonPath('result.messages_moved', 0);
    }

    /** @test */
    public function it_returns_422_when_validation_fails(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/rebalance', []);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['details' => ['from_sim_id', 'to_sim_id']]);
    }

    /** @test */
    public function it_returns_422_when_source_sim_is_outside_tenant_boundary(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $sourceSimOtherTenant = $this->createSim($companyB);
        $destinationSim = $this->createSim($companyA);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/rebalance', [
                'from_sim_id' => $sourceSimOtherTenant->id,
                'to_sim_id' => $destinationSim->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Source SIM does not belong to the provided company.');
    }

    /** @test */
    public function it_returns_422_when_destination_sim_is_blocked(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company, ['operator_status' => 'active']);
        $blockedDestination = $this->createSim($company, ['operator_status' => 'blocked']);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/rebalance', [
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $blockedDestination->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Destination SIM is blocked and cannot receive migrated traffic.');
    }

    /** @test */
    public function it_returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/admin/rebalance', [
            'from_sim_id' => 1,
            'to_sim_id' => 2,
        ]);

        $response->assertStatus(401);
    }

    /**
     * @param string $apiKey
     * @param string $apiSecret
     * @return array<string, string>
     */
    private function authHeaders(string $apiKey, string $apiSecret): array
    {
        return [
            'X-API-KEY' => $apiKey,
            'X-API-SECRET' => $apiSecret,
        ];
    }

    private function createOutboundMessage(
        int $companyId,
        int $simId,
        string $customerPhone,
        string $status
    ): OutboundMessage {
        return OutboundMessage::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'sim_id' => $simId,
            'customer_phone' => $customerPhone,
            'message' => 'Rebalance test message '.$status,
            'message_type' => 'CHAT',
            'status' => $status,
            'retry_count' => 0,
        ]);
    }
}
