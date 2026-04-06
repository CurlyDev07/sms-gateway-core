<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimAdminControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_updates_operator_status_and_returns_sim_state(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status'              => 'active',
            'status'                       => 'active',
            'accept_new_assignments'       => true,
            'disabled_for_new_assignments' => false,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/status', [
                'operator_status' => 'paused',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sim.id', $sim->id)
            ->assertJsonPath('sim.company_id', $company->id)
            ->assertJsonPath('sim.operator_status', 'paused')
            ->assertJsonPath('sim.status', 'active')
            ->assertJsonPath('sim.accept_new_assignments', true)
            ->assertJsonPath('sim.disabled_for_new_assignments', false);

        $this->assertDatabaseHas('sims', [
            'id'              => $sim->id,
            'operator_status' => 'paused',
        ]);
    }

    /** @test */
    public function it_accepts_all_valid_operator_statuses(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        foreach (['active', 'paused', 'blocked'] as $status) {
            $sim = $this->createSim($company, ['operator_status' => 'active']);

            $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
                ->postJson('/api/admin/sim/'.$sim->id.'/status', [
                    'operator_status' => $status,
                ]);

            $response->assertStatus(200)
                ->assertJsonPath('sim.operator_status', $status);
        }
    }

    /** @test */
    public function it_is_idempotent_when_status_is_already_set(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'paused']);
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/status', [
                'operator_status' => 'paused',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sim.operator_status', 'paused');
    }

    /** @test */
    public function it_returns_422_for_invalid_operator_status(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/status', [
                'operator_status' => 'offline',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed');
    }

    /** @test */
    public function it_returns_422_when_operator_status_is_missing(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/status', []);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed');
    }

    /** @test */
    public function it_returns_404_for_sim_belonging_to_another_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $simB = $this->createSim($companyB);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$simB->id.'/status', [
                'operator_status' => 'paused',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'sim_not_found');
    }

    /** @test */
    public function it_returns_404_for_nonexistent_sim(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/99999/status', [
                'operator_status' => 'paused',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'sim_not_found');
    }

    /** @test */
    public function it_returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/admin/sim/1/status', [
            'operator_status' => 'paused',
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
