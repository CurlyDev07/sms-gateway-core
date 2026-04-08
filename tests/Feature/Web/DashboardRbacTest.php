<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Services\RedisQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class DashboardRbacTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    public function test_support_role_can_access_dashboard_read_endpoints(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $redisQueueService = Mockery::mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('depth')->andReturn(0);
        $this->app->instance(RedisQueueService::class, $redisQueueService);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $this->actingAs($user)
            ->getJson('/dashboard/api/sims')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sims.0.id', $sim->id);
    }

    public function test_support_role_is_forbidden_from_dashboard_write_endpoints(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $this->actingAs($user)
            ->postJson('/dashboard/api/admin/sim/'.$sim->id.'/status', [
                'operator_status' => 'paused',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');

        $sim->refresh();
        $this->assertSame('active', $sim->operator_status);
    }

    public function test_owner_role_can_execute_dashboard_write_endpoints(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
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
