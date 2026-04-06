<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimAssignmentToggleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    // -------------------------------------------------------------------------
    // enable-assignments
    // -------------------------------------------------------------------------

    /** @test */
    public function enable_sets_accept_new_assignments_to_true_and_returns_sim_state(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'accept_new_assignments'       => false,
            'disabled_for_new_assignments' => false,
            'operator_status'              => 'active',
            'status'                       => 'active',
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/enable-assignments');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sim.id', $sim->id)
            ->assertJsonPath('sim.company_id', $company->id)
            ->assertJsonPath('sim.accept_new_assignments', true)
            ->assertJsonPath('sim.disabled_for_new_assignments', false)
            ->assertJsonPath('sim.operator_status', 'active')
            ->assertJsonPath('sim.status', 'active');

        $this->assertDatabaseHas('sims', [
            'id'                     => $sim->id,
            'accept_new_assignments' => true,
        ]);
    }

    /** @test */
    public function enable_is_idempotent_when_already_enabled(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['accept_new_assignments' => true]);
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/enable-assignments');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sim.accept_new_assignments', true);

        $this->assertDatabaseHas('sims', [
            'id'                     => $sim->id,
            'accept_new_assignments' => true,
        ]);
    }

    /** @test */
    public function enable_returns_404_for_sim_belonging_to_another_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $simB = $this->createSim($companyB, ['accept_new_assignments' => false]);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$simB->id.'/enable-assignments');

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'sim_not_found');

        $this->assertDatabaseHas('sims', [
            'id'                     => $simB->id,
            'accept_new_assignments' => false,
        ]);
    }

    /** @test */
    public function enable_returns_404_for_nonexistent_sim(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/99999/enable-assignments');

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'sim_not_found');
    }

    // -------------------------------------------------------------------------
    // disable-assignments
    // -------------------------------------------------------------------------

    /** @test */
    public function disable_sets_accept_new_assignments_to_false_and_returns_sim_state(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'accept_new_assignments'       => true,
            'disabled_for_new_assignments' => false,
            'operator_status'              => 'active',
            'status'                       => 'active',
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/disable-assignments');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sim.id', $sim->id)
            ->assertJsonPath('sim.company_id', $company->id)
            ->assertJsonPath('sim.accept_new_assignments', false)
            ->assertJsonPath('sim.disabled_for_new_assignments', false)
            ->assertJsonPath('sim.operator_status', 'active')
            ->assertJsonPath('sim.status', 'active');

        $this->assertDatabaseHas('sims', [
            'id'                     => $sim->id,
            'accept_new_assignments' => false,
        ]);
    }

    /** @test */
    public function disable_is_idempotent_when_already_disabled(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['accept_new_assignments' => false]);
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/disable-assignments');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sim.accept_new_assignments', false);

        $this->assertDatabaseHas('sims', [
            'id'                     => $sim->id,
            'accept_new_assignments' => false,
        ]);
    }

    /** @test */
    public function disable_returns_404_for_sim_belonging_to_another_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $simB = $this->createSim($companyB, ['accept_new_assignments' => true]);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$simB->id.'/disable-assignments');

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'sim_not_found');

        $this->assertDatabaseHas('sims', [
            'id'                     => $simB->id,
            'accept_new_assignments' => true,
        ]);
    }

    /** @test */
    public function disable_returns_404_for_nonexistent_sim(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/99999/disable-assignments');

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'sim_not_found');
    }

    /** @test */
    public function both_endpoints_return_401_when_unauthenticated(): void
    {
        $this->postJson('/api/admin/sim/1/enable-assignments')->assertStatus(401);
        $this->postJson('/api/admin/sim/1/disable-assignments')->assertStatus(401);
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
