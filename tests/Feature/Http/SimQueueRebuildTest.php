<?php

namespace Tests\Feature\Http;

use App\Services\QueueRebuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimQueueRebuildTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_triggers_rebuild_and_returns_result(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        [$apiClient, $secret] = $this->createApiClient($company);

        $expectedResult = [
            'company_id'    => $company->id,
            'sim_id'        => $sim->id,
            'pending_count' => 3,
            'enqueued_count' => 3,
            'chat_count'    => 2,
            'followup_count' => 1,
            'blasting_count' => 0,
            'lock_key'      => 'sms:lock:rebuild:sim:'.$sim->id,
        ];

        $this->mock(QueueRebuildService::class, function ($mock) use ($company, $sim, $expectedResult) {
            $mock->shouldReceive('rebuildSimQueue')
                ->once()
                ->with($company->id, $sim->id)
                ->andReturn($expectedResult);
        });

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/rebuild-queue');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.company_id', $company->id)
            ->assertJsonPath('result.sim_id', $sim->id)
            ->assertJsonPath('result.pending_count', 3)
            ->assertJsonPath('result.enqueued_count', 3)
            ->assertJsonPath('result.chat_count', 2)
            ->assertJsonPath('result.followup_count', 1)
            ->assertJsonPath('result.blasting_count', 0)
            ->assertJsonPath('result.lock_key', 'sms:lock:rebuild:sim:'.$sim->id);
    }

    /** @test */
    public function it_passes_the_authenticated_tenant_company_id_to_the_service(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        [$apiClient, $secret] = $this->createApiClient($company);

        $this->mock(QueueRebuildService::class, function ($mock) use ($company, $sim) {
            $mock->shouldReceive('rebuildSimQueue')
                ->once()
                ->with($company->id, $sim->id)
                ->andReturn([
                    'company_id' => $company->id, 'sim_id' => $sim->id,
                    'pending_count' => 0, 'enqueued_count' => 0,
                    'chat_count' => 0, 'followup_count' => 0, 'blasting_count' => 0,
                    'lock_key' => 'sms:lock:rebuild:sim:'.$sim->id,
                ]);
        });

        $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/rebuild-queue')
            ->assertStatus(200);
    }

    /** @test */
    public function it_returns_409_when_rebuild_lock_is_already_held(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        [$apiClient, $secret] = $this->createApiClient($company);

        $this->mock(QueueRebuildService::class, function ($mock) use ($company, $sim) {
            $mock->shouldReceive('rebuildSimQueue')
                ->once()
                ->with($company->id, $sim->id)
                ->andThrow(new RuntimeException('Queue rebuild already in progress for this SIM.'));
        });

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/rebuild-queue');

        $response->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Queue rebuild already in progress for this SIM.');
    }

    /** @test */
    public function it_returns_422_when_sim_does_not_belong_to_tenant(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        [$apiClient, $secret] = $this->createApiClient($company);

        $this->mock(QueueRebuildService::class, function ($mock) use ($company, $sim) {
            $mock->shouldReceive('rebuildSimQueue')
                ->once()
                ->with($company->id, $sim->id)
                ->andThrow(new InvalidArgumentException('SIM does not belong to the provided company.'));
        });

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->postJson('/api/admin/sim/'.$sim->id.'/rebuild-queue');

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'SIM does not belong to the provided company.');
    }

    /** @test */
    public function it_returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/admin/sim/1/rebuild-queue');

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
