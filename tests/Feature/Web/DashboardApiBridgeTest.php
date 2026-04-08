<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Services\RedisQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class DashboardApiBridgeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    public function test_dashboard_api_requires_user_company_binding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/dashboard/api/sims')
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'dashboard_tenant_not_bound');
    }

    public function test_dashboard_api_uses_server_side_tenant_binding(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $redisQueueService = Mockery::mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('depth')->andReturn(0);
        $this->app->instance(RedisQueueService::class, $redisQueueService);

        $user = User::factory()->create([
            'company_id' => $company->id,
        ]);

        $this->actingAs($user)
            ->getJson('/dashboard/api/sims')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'sims')
            ->assertJsonPath('sims.0.id', $sim->id);
    }

    public function test_dashboard_api_can_update_sim_status_without_browser_api_secret_headers(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $user = User::factory()->create([
            'company_id' => $company->id,
        ]);

        $this->actingAs($user)
            ->postJson('/dashboard/api/admin/sim/'.$sim->id.'/status', [
                'operator_status' => 'paused',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sim.operator_status', 'paused');
    }
}
