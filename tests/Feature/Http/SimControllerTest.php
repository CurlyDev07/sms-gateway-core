<?php

namespace Tests\Feature\Http;

use App\Services\RedisQueueService;
use App\Models\SimHealthLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent real Redis calls in every test unless overridden.
        $this->mock(RedisQueueService::class, function ($mock) {
            $mock->shouldReceive('depth')->andReturn(0);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_returns_sims_for_authenticated_tenant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 10:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'phone_number'   => '+639171111111',
            'carrier'        => 'Globe',
            'sim_label'      => 'sim-a',
            'status'         => 'active',
            'operator_status' => 'active',
            'accept_new_assignments'       => true,
            'disabled_for_new_assignments' => false,
            'last_success_at' => Carbon::now()->subMinutes(10),
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/sims');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'sims')
            ->assertJsonPath('sims.0.id', $sim->id)
            ->assertJsonPath('sims.0.uuid', $sim->uuid)
            ->assertJsonPath('sims.0.phone_number', '+639171111111')
            ->assertJsonPath('sims.0.carrier', 'Globe')
            ->assertJsonPath('sims.0.sim_label', 'sim-a')
            ->assertJsonPath('sims.0.status', 'active')
            ->assertJsonPath('sims.0.operator_status', 'active')
            ->assertJsonPath('sims.0.accept_new_assignments', true)
            ->assertJsonPath('sims.0.disabled_for_new_assignments', false)
            ->assertJsonPath('sims.0.health.status', 'healthy')
            ->assertJsonPath('sims.0.health.reason', null)
            ->assertJsonPath('sims.0.health.minutes_since_last_success', 10)
            ->assertJsonPath('sims.0.health.runtime_control.suppressed', false)
            ->assertJsonPath('sims.0.stuck.stuck_6h', false)
            ->assertJsonPath('sims.0.stuck.stuck_24h', false)
            ->assertJsonPath('sims.0.stuck.stuck_3d', false)
            ->assertJsonPath('sims.0.queue_depth.total', 0)
            ->assertJsonPath('sims.0.queue_depth.chat', 0)
            ->assertJsonPath('sims.0.queue_depth.followup', 0)
            ->assertJsonPath('sims.0.queue_depth.blasting', 0);
    }

    /** @test */
    public function it_returns_only_sims_belonging_to_the_authenticated_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $simA = $this->createSim($companyA);
        $this->createSim($companyB);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/sims');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'sims')
            ->assertJsonPath('sims.0.id', $simA->id);
    }

    /** @test */
    public function it_returns_unhealthy_status_for_sim_with_no_recent_success(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 10:00:00'));

        $company = $this->createCompany();
        $this->createSim($company, [
            'last_success_at' => null,
            'disabled_for_new_assignments' => false,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/sims');

        $response->assertStatus(200)
            ->assertJsonPath('sims.0.health.status', 'unhealthy')
            ->assertJsonPath('sims.0.health.reason', 'no_success_recorded')
            ->assertJsonPath('sims.0.health.minutes_since_last_success', null)
            ->assertJsonPath('sims.0.health.runtime_control.suppressed', false)
            ->assertJsonPath('sims.0.stuck.stuck_6h', true)
            ->assertJsonPath('sims.0.stuck.stuck_24h', true)
            ->assertJsonPath('sims.0.stuck.stuck_3d', true);
    }

    /** @test */
    public function it_returns_runtime_control_snapshot_when_sim_has_runtime_failures_and_active_suppression(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10 12:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(5),
            'mode' => 'COOLDOWN',
            'cooldown_until' => Carbon::now()->addMinutes(10),
        ]);

        SimHealthLog::query()->create([
            'sim_id' => $sim->id,
            'status' => 'error',
            'error_message' => json_encode([
                'error' => 'RUNTIME_TIMEOUT',
                'error_layer' => 'transport',
                'classification' => 'retryable',
                'retryable' => true,
            ]),
            'logged_at' => now()->subMinutes(3),
        ]);

        SimHealthLog::query()->create([
            'sim_id' => $sim->id,
            'status' => 'error',
            'error_message' => json_encode([
                'error' => 'RUNTIME_TIMEOUT',
                'error_layer' => 'transport',
                'classification' => 'retryable',
                'retryable' => true,
            ]),
            'logged_at' => now()->subMinutes(2),
        ]);

        SimHealthLog::query()->create([
            'sim_id' => $sim->id,
            'status' => 'error',
            'error_message' => json_encode([
                'error' => 'INVALID_RESPONSE',
                'error_layer' => 'python_api',
                'classification' => 'non_retryable',
                'retryable' => false,
            ]),
            'logged_at' => now()->subMinute(),
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/sims')
            ->assertOk()
            ->assertJsonPath('sims.0.health.runtime_control.suppressed', true)
            ->assertJsonPath('sims.0.health.runtime_control.last_error', 'INVALID_RESPONSE')
            ->assertJsonPath('sims.0.health.runtime_control.last_error_layer', 'python_api')
            ->assertJsonPath('sims.0.health.runtime_control.last_classification', 'non_retryable')
            ->assertJsonPath('sims.0.health.runtime_control.last_retryable', false);
    }

    /** @test */
    public function it_returns_queue_depth_from_redis_queue_service(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $simId = (int) $sim->id;

        // Override the default mock for this test to return specific depths.
        $this->mock(RedisQueueService::class, function ($mock) use ($simId) {
            $mock->shouldReceive('depth')->with($simId)->andReturn(7);
            $mock->shouldReceive('depth')->with($simId, 'chat')->andReturn(5);
            $mock->shouldReceive('depth')->with($simId, 'followup')->andReturn(2);
            $mock->shouldReceive('depth')->with($simId, 'blasting')->andReturn(0);
        });

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/sims');

        $response->assertStatus(200)
            ->assertJsonPath('sims.0.queue_depth.total', 7)
            ->assertJsonPath('sims.0.queue_depth.chat', 5)
            ->assertJsonPath('sims.0.queue_depth.followup', 2)
            ->assertJsonPath('sims.0.queue_depth.blasting', 0);
    }

    /** @test */
    public function it_returns_403_when_request_has_no_valid_tenant(): void
    {
        $response = $this->getJson('/api/sims');

        // No auth headers → middleware rejects before controller; 401 or 403 depending on middleware order.
        $response->assertStatus(401);
    }

    /** @test */
    public function it_returns_empty_sims_array_when_tenant_has_no_sims(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/sims');

        $response->assertStatus(200)
            ->assertJson(['ok' => true, 'sims' => []]);
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
