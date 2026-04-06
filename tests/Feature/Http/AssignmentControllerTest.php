<?php

namespace Tests\Feature\Http;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class AssignmentControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_returns_assignments_for_authenticated_tenant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 10:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'phone_number'    => '+639171111111',
            'operator_status' => 'active',
            'status'          => 'active',
        ]);

        $assignment = $this->createAssignment($company, $sim, '09181234567', [
            'status'          => 'active',
            'has_replied'     => true,
            'safe_to_migrate' => false,
            'assigned_at'     => Carbon::parse('2026-04-06 08:00:00'),
            'last_used_at'    => Carbon::parse('2026-04-06 09:00:00'),
            'last_inbound_at' => Carbon::parse('2026-04-06 09:30:00'),
            'last_outbound_at' => Carbon::parse('2026-04-06 09:45:00'),
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/assignments');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $assignment->id)
            ->assertJsonPath('assignments.0.customer_phone', '09181234567')
            ->assertJsonPath('assignments.0.sim_id', $sim->id)
            ->assertJsonPath('assignments.0.status', 'active')
            ->assertJsonPath('assignments.0.has_replied', true)
            ->assertJsonPath('assignments.0.safe_to_migrate', false)
            ->assertJsonPath('assignments.0.assigned_at', '2026-04-06T08:00:00+00:00')
            ->assertJsonPath('assignments.0.last_used_at', '2026-04-06T09:00:00+00:00')
            ->assertJsonPath('assignments.0.last_inbound_at', '2026-04-06T09:30:00+00:00')
            ->assertJsonPath('assignments.0.last_outbound_at', '2026-04-06T09:45:00+00:00')
            ->assertJsonPath('assignments.0.sim.id', $sim->id)
            ->assertJsonPath('assignments.0.sim.phone_number', '+639171111111')
            ->assertJsonPath('assignments.0.sim.operator_status', 'active')
            ->assertJsonPath('assignments.0.sim.status', 'active');
    }

    /** @test */
    public function it_returns_only_assignments_belonging_to_the_authenticated_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $simA = $this->createSim($companyA);
        $simB = $this->createSim($companyB);

        $assignmentA = $this->createAssignment($companyA, $simA, '09171111111');
        $this->createAssignment($companyB, $simB, '09172222222');

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/assignments');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $assignmentA->id);
    }

    /** @test */
    public function it_filters_by_customer_phone_when_provided(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        $simB = $this->createSim($company);

        $this->createAssignment($company, $sim, '09171111111');
        $assignmentB = $this->createAssignment($company, $simB, '09172222222');

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/assignments?customer_phone=09172222222');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $assignmentB->id)
            ->assertJsonPath('assignments.0.customer_phone', '09172222222');
    }

    /** @test */
    public function it_filters_by_sim_id_when_provided(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);

        $assignmentA = $this->createAssignment($company, $simA, '09171111111');
        $this->createAssignment($company, $simB, '09172222222');

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/assignments?sim_id='.$simA->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $assignmentA->id)
            ->assertJsonPath('assignments.0.sim_id', $simA->id);
    }

    /** @test */
    public function it_returns_empty_assignments_array_when_tenant_has_none(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/assignments');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJson(['assignments' => []]);
    }

    /** @test */
    public function it_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/assignments');

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // assignments/set
    // -------------------------------------------------------------------------

    /** @test */
    public function set_creates_new_assignment_when_none_exists(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['status' => 'active', 'operator_status' => 'active']);
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/set', [
                'customer_phone' => '09171234567',
                'sim_id'         => $sim->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assignment.company_id', $company->id)
            ->assertJsonPath('assignment.customer_phone', '09171234567')
            ->assertJsonPath('assignment.sim_id', $sim->id)
            ->assertJsonPath('assignment.has_replied', false)
            ->assertJsonPath('assignment.safe_to_migrate', false);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'company_id'     => $company->id,
            'customer_phone' => '09171234567',
            'sim_id'         => $sim->id,
            'status'         => 'active',
        ]);
    }

    /** @test */
    public function set_updates_sim_on_existing_assignment_without_touching_other_fields(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);

        $existing = $this->createAssignment($company, $simA, '09171234567', [
            'has_replied'     => true,
            'safe_to_migrate' => true,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/set', [
                'customer_phone' => '09171234567',
                'sim_id'         => $simB->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assignment.id', $existing->id)
            ->assertJsonPath('assignment.sim_id', $simB->id)
            ->assertJsonPath('assignment.has_replied', true)
            ->assertJsonPath('assignment.safe_to_migrate', true);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'id'              => $existing->id,
            'sim_id'          => $simB->id,
            'has_replied'     => true,
            'safe_to_migrate' => true,
        ]);
    }

    /** @test */
    public function set_returns_404_for_sim_belonging_to_another_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $simB = $this->createSim($companyB);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/set', [
                'customer_phone' => '09171234567',
                'sim_id'         => $simB->id,
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'sim_not_found');

        $this->assertDatabaseMissing('customer_sim_assignments', [
            'company_id'     => $companyA->id,
            'customer_phone' => '09171234567',
        ]);
    }

    /** @test */
    public function set_returns_404_for_nonexistent_sim(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/set', [
                'customer_phone' => '09171234567',
                'sim_id'         => 99999,
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'sim_not_found');
    }

    /** @test */
    public function set_returns_422_when_required_fields_are_missing(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/set', []);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['details' => ['customer_phone', 'sim_id']]);
    }

    /** @test */
    public function set_returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/assignments/set', [
            'customer_phone' => '09171234567',
            'sim_id'         => 1,
        ]);

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // assignments/mark-safe
    // -------------------------------------------------------------------------

    /** @test */
    public function mark_safe_sets_safe_to_migrate_true_and_returns_assignment(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $assignment = $this->createAssignment($company, $sim, '09171234567', [
            'has_replied'     => true,
            'safe_to_migrate' => false,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/mark-safe', [
                'customer_phone' => '09171234567',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assignment.id', $assignment->id)
            ->assertJsonPath('assignment.company_id', $company->id)
            ->assertJsonPath('assignment.customer_phone', '09171234567')
            ->assertJsonPath('assignment.sim_id', $sim->id)
            ->assertJsonPath('assignment.safe_to_migrate', true)
            ->assertJsonPath('assignment.has_replied', true);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'id'              => $assignment->id,
            'safe_to_migrate' => true,
        ]);
    }

    /** @test */
    public function mark_safe_is_idempotent_when_already_safe(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $assignment = $this->createAssignment($company, $sim, '09171234567', [
            'safe_to_migrate' => true,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/mark-safe', [
                'customer_phone' => '09171234567',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assignment.safe_to_migrate', true);
    }

    /** @test */
    public function mark_safe_does_not_affect_other_fields(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $assignment = $this->createAssignment($company, $sim, '09171234567', [
            'has_replied'     => false,
            'safe_to_migrate' => false,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/mark-safe', ['customer_phone' => '09171234567'])
            ->assertStatus(200);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'id'          => $assignment->id,
            'sim_id'      => $sim->id,
            'has_replied' => false,
        ]);
    }

    /** @test */
    public function mark_safe_returns_404_when_no_assignment_exists_for_customer(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/mark-safe', [
                'customer_phone' => '09179999999',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'assignment_not_found');
    }

    /** @test */
    public function mark_safe_returns_404_for_customer_belonging_to_another_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $simB = $this->createSim($companyB);

        $this->createAssignment($companyB, $simB, '09171234567');

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/mark-safe', [
                'customer_phone' => '09171234567',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'assignment_not_found');

        $this->assertDatabaseHas('customer_sim_assignments', [
            'company_id'      => $companyB->id,
            'customer_phone'  => '09171234567',
            'safe_to_migrate' => false,
        ]);
    }

    /** @test */
    public function mark_safe_returns_422_when_customer_phone_is_missing(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/assignments/mark-safe', []);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['details' => ['customer_phone']]);
    }

    /** @test */
    public function mark_safe_returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/assignments/mark-safe', [
            'customer_phone' => '09171234567',
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
