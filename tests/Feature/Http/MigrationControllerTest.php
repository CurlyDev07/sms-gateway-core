<?php

namespace Tests\Feature\Http;

use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class MigrationControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_migrates_customer_assignment_and_pending_messages_to_new_sim(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);

        $assignment = $this->createAssignment($company, $simA, '09171234567');

        // Pending message — should be moved.
        $pending = OutboundMessage::query()->create([
            'uuid'           => (string) Str::uuid(),
            'company_id'     => $company->id,
            'sim_id'         => $simA->id,
            'customer_phone' => '09171234567',
            'message'        => 'Hello',
            'message_type'   => 'CHAT',
            'status'         => 'pending',
            'retry_count'    => 0,
        ]);

        // Sent message — should NOT be moved.
        $sent = OutboundMessage::query()->create([
            'uuid'           => (string) Str::uuid(),
            'company_id'     => $company->id,
            'sim_id'         => $simA->id,
            'customer_phone' => '09171234567',
            'message'        => 'Already sent',
            'message_type'   => 'CHAT',
            'status'         => 'sent',
            'retry_count'    => 0,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-single-customer', [
                'from_sim_id'    => $simA->id,
                'to_sim_id'      => $simB->id,
                'customer_phone' => '09171234567',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.from_sim_id', $simA->id)
            ->assertJsonPath('result.to_sim_id', $simB->id)
            ->assertJsonPath('result.customer_phone', '09171234567')
            ->assertJsonPath('result.assignments_moved', 1)
            ->assertJsonPath('result.messages_moved', 1);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'id'     => $assignment->id,
            'sim_id' => $simB->id,
        ]);

        $this->assertDatabaseHas('outbound_messages', [
            'id'     => $pending->id,
            'sim_id' => $simB->id,
        ]);

        $this->assertDatabaseHas('outbound_messages', [
            'id'     => $sent->id,
            'sim_id' => $simA->id,
        ]);
    }

    /** @test */
    public function it_returns_zero_moved_when_customer_has_no_assignment_on_source_sim(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-single-customer', [
                'from_sim_id'    => $simA->id,
                'to_sim_id'      => $simB->id,
                'customer_phone' => '09179999999',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.assignments_moved', 0)
            ->assertJsonPath('result.messages_moved', 0);
    }

    /** @test */
    public function it_returns_422_when_from_and_to_sim_are_the_same(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-single-customer', [
                'from_sim_id'    => $sim->id,
                'to_sim_id'      => $sim->id,
                'customer_phone' => '09171234567',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /** @test */
    public function it_returns_422_when_destination_sim_is_blocked(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company, ['operator_status' => 'active']);
        $simB = $this->createSim($company, ['operator_status' => 'blocked']);

        $this->createAssignment($company, $simA, '09171234567');

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-single-customer', [
                'from_sim_id'    => $simA->id,
                'to_sim_id'      => $simB->id,
                'customer_phone' => '09171234567',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /** @test */
    public function it_returns_422_when_source_sim_belongs_to_another_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $simFromB = $this->createSim($companyB);
        $simToA = $this->createSim($companyA);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-single-customer', [
                'from_sim_id'    => $simFromB->id,
                'to_sim_id'      => $simToA->id,
                'customer_phone' => '09171234567',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /** @test */
    public function it_returns_422_when_destination_sim_belongs_to_another_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $simFromA = $this->createSim($companyA);
        $simToB = $this->createSim($companyB);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-single-customer', [
                'from_sim_id'    => $simFromA->id,
                'to_sim_id'      => $simToB->id,
                'customer_phone' => '09171234567',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /** @test */
    public function it_returns_422_when_required_fields_are_missing(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-single-customer', []);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['details' => ['from_sim_id', 'to_sim_id', 'customer_phone']]);
    }

    /** @test */
    public function it_returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/admin/migrate-single-customer', [
            'from_sim_id'    => 1,
            'to_sim_id'      => 2,
            'customer_phone' => '09171234567',
        ]);

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // migrate-bulk
    // -------------------------------------------------------------------------

    /** @test */
    public function bulk_migrates_all_assignments_and_pending_messages_to_new_sim(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);

        $assignmentOne = $this->createAssignment($company, $simA, '09171111111');
        $assignmentTwo = $this->createAssignment($company, $simA, '09172222222');

        $pending = OutboundMessage::query()->create([
            'uuid'           => (string) \Illuminate\Support\Str::uuid(),
            'company_id'     => $company->id,
            'sim_id'         => $simA->id,
            'customer_phone' => '09171111111',
            'message'        => 'Hello',
            'message_type'   => 'CHAT',
            'status'         => 'pending',
            'retry_count'    => 0,
        ]);

        $sent = OutboundMessage::query()->create([
            'uuid'           => (string) \Illuminate\Support\Str::uuid(),
            'company_id'     => $company->id,
            'sim_id'         => $simA->id,
            'customer_phone' => '09171111111',
            'message'        => 'Already sent',
            'message_type'   => 'CHAT',
            'status'         => 'sent',
            'retry_count'    => 0,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-bulk', [
                'from_sim_id' => $simA->id,
                'to_sim_id'   => $simB->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.from_sim_id', $simA->id)
            ->assertJsonPath('result.to_sim_id', $simB->id)
            ->assertJsonPath('result.assignments_moved', 2)
            ->assertJsonPath('result.messages_moved', 1);

        $this->assertDatabaseHas('customer_sim_assignments', ['id' => $assignmentOne->id, 'sim_id' => $simB->id]);
        $this->assertDatabaseHas('customer_sim_assignments', ['id' => $assignmentTwo->id, 'sim_id' => $simB->id]);
        $this->assertDatabaseHas('outbound_messages', ['id' => $pending->id, 'sim_id' => $simB->id]);
        $this->assertDatabaseHas('outbound_messages', ['id' => $sent->id, 'sim_id' => $simA->id]);
    }

    /** @test */
    public function bulk_returns_zero_moved_when_source_sim_has_no_assignments(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-bulk', [
                'from_sim_id' => $simA->id,
                'to_sim_id'   => $simB->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.assignments_moved', 0)
            ->assertJsonPath('result.messages_moved', 0);
    }

    /** @test */
    public function bulk_returns_422_when_from_and_to_sim_are_the_same(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-bulk', [
                'from_sim_id' => $sim->id,
                'to_sim_id'   => $sim->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /** @test */
    public function bulk_returns_422_when_destination_sim_is_blocked(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company, ['operator_status' => 'active']);
        $simB = $this->createSim($company, ['operator_status' => 'blocked']);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-bulk', [
                'from_sim_id' => $simA->id,
                'to_sim_id'   => $simB->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /** @test */
    public function bulk_returns_422_when_source_sim_belongs_to_another_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $simFromB = $this->createSim($companyB);
        $simToA = $this->createSim($companyA);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-bulk', [
                'from_sim_id' => $simFromB->id,
                'to_sim_id'   => $simToA->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /** @test */
    public function bulk_returns_422_when_required_fields_are_missing(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/migrate-bulk', []);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['details' => ['from_sim_id', 'to_sim_id']]);
    }

    /** @test */
    public function bulk_returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/admin/migrate-bulk', [
            'from_sim_id' => 1,
            'to_sim_id'   => 2,
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
            'X-API-KEY'    => $apiKey,
            'X-API-SECRET' => $apiSecret,
        ];
    }
}
