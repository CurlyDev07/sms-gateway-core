<?php

namespace Tests\Feature\Commands;

use App\Services\QueueRebuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class InitializeQueueMigrationCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_rejects_unsafe_scope_combinations(): void
    {
        $this->artisan('gateway:init-queue-migration', [
            '--all' => true,
            '--company-id' => 1,
        ])->expectsOutput('--all cannot be combined with --company-id or --sim-id.')
            ->assertExitCode(1);
    }

    /** @test */
    public function sim_id_requires_company_id(): void
    {
        $this->artisan('gateway:init-queue-migration', [
            '--sim-id' => 10,
        ])->expectsOutput('--sim-id requires --company-id for explicit tenant boundary.')
            ->assertExitCode(1);
    }

    /** @test */
    public function unscoped_run_without_all_is_rejected(): void
    {
        $this->artisan('gateway:init-queue-migration')
            ->expectsOutput('Refusing unscoped run. Use --all or provide --company-id (optionally with --sim-id).')
            ->assertExitCode(1);
    }

    /** @test */
    public function one_company_scope_rebuilds_only_that_company_sims(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPICA']);
        $companyB = $this->createCompany(['code' => 'CMPICB']);
        $simA1 = $this->createSim($companyA);
        $simA2 = $this->createSim($companyA);
        $this->createSim($companyB);

        $calls = [];
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')
            ->times(2)
            ->andReturnUsing(function (int $companyId, int $simId) use (&$calls) {
                $calls[] = [$companyId, $simId];

                return [
                    'company_id' => $companyId,
                    'sim_id' => $simId,
                    'pending_count' => 1,
                    'enqueued_count' => 1,
                    'chat_count' => 1,
                    'followup_count' => 0,
                    'blasting_count' => 0,
                    'lock_key' => "sms:lock:rebuild:sim:{$simId}",
                ];
            });
        $this->app->instance(QueueRebuildService::class, $mock);

        $this->artisan('gateway:init-queue-migration', [
            '--company-id' => $companyA->id,
        ])->expectsOutput('Queue migration initialization completed.')
            ->expectsOutput('Target SIMs: 2')
            ->expectsOutput('Processed: 2')
            ->expectsOutput('Failed: 0')
            ->assertExitCode(0);

        $this->assertCount(2, $calls);
        $this->assertEqualsCanonicalizing(
            [
                [$companyA->id, $simA1->id],
                [$companyA->id, $simA2->id],
            ],
            $calls
        );
    }

    /** @test */
    public function one_sim_scope_rebuilds_only_target_sim(): void
    {
        $company = $this->createCompany();
        $sim1 = $this->createSim($company);
        $sim2 = $this->createSim($company);

        $calls = [];
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')
            ->once()
            ->with($company->id, $sim2->id)
            ->andReturnUsing(function (int $companyId, int $simId) use (&$calls) {
                $calls[] = [$companyId, $simId];

                return [
                    'company_id' => $companyId,
                    'sim_id' => $simId,
                    'pending_count' => 0,
                    'enqueued_count' => 0,
                    'chat_count' => 0,
                    'followup_count' => 0,
                    'blasting_count' => 0,
                    'lock_key' => "sms:lock:rebuild:sim:{$simId}",
                ];
            });
        $this->app->instance(QueueRebuildService::class, $mock);

        $this->artisan('gateway:init-queue-migration', [
            '--company-id' => $company->id,
            '--sim-id' => $sim2->id,
        ])->expectsOutput('Queue migration initialization completed.')
            ->expectsOutput('Target SIMs: 1')
            ->expectsOutput('Processed: 1')
            ->expectsOutput('Failed: 0')
            ->assertExitCode(0);

        $this->assertCount(1, $calls);
        $this->assertSame([$company->id, $sim2->id], $calls[0]);
        $this->assertNotSame($sim1->id, $calls[0][1]);
    }

    /** @test */
    public function all_scope_rebuilds_all_sims(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPALLA']);
        $companyB = $this->createCompany(['code' => 'CMPALLB']);
        $simA = $this->createSim($companyA);
        $simB = $this->createSim($companyB);

        $calls = [];
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')
            ->times(2)
            ->andReturnUsing(function (int $companyId, int $simId) use (&$calls) {
                $calls[] = [$companyId, $simId];

                return [
                    'company_id' => $companyId,
                    'sim_id' => $simId,
                    'pending_count' => 2,
                    'enqueued_count' => 2,
                    'chat_count' => 1,
                    'followup_count' => 1,
                    'blasting_count' => 0,
                    'lock_key' => "sms:lock:rebuild:sim:{$simId}",
                ];
            });
        $this->app->instance(QueueRebuildService::class, $mock);

        $this->artisan('gateway:init-queue-migration', [
            '--all' => true,
        ])->expectsOutput('Queue migration initialization completed.')
            ->expectsOutput('Scope: all')
            ->expectsOutput('Target SIMs: 2')
            ->expectsOutput('Processed: 2')
            ->expectsOutput('Failed: 0')
            ->assertExitCode(0);

        $this->assertCount(2, $calls);
        $this->assertEqualsCanonicalizing(
            [
                [$companyA->id, $simA->id],
                [$companyB->id, $simB->id],
            ],
            $calls
        );
    }

    /** @test */
    public function partial_failure_returns_failure_while_continuing_per_sim_processing(): void
    {
        $company = $this->createCompany();
        $sim1 = $this->createSim($company);
        $sim2 = $this->createSim($company);

        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')
            ->twice()
            ->andReturnUsing(function (int $companyId, int $simId) use ($sim1) {
                if ($simId === (int) $sim1->id) {
                    return [
                        'company_id' => $companyId,
                        'sim_id' => $simId,
                        'pending_count' => 3,
                        'enqueued_count' => 3,
                        'chat_count' => 2,
                        'followup_count' => 1,
                        'blasting_count' => 0,
                        'lock_key' => "sms:lock:rebuild:sim:{$simId}",
                    ];
                }

                throw new RuntimeException('Queue rebuild already in progress for this SIM.');
            });
        $this->app->instance(QueueRebuildService::class, $mock);

        $this->artisan('gateway:init-queue-migration', [
            '--company-id' => $company->id,
        ])->expectsOutput('Rebuilt SIM '.$sim1->id.' (company '.$company->id.') pending=3, enqueued=3')
            ->expectsOutput('Failed SIM '.$sim2->id.' (company '.$company->id.'): Queue rebuild already in progress for this SIM.')
            ->expectsOutput('Queue migration initialization completed.')
            ->expectsOutput('Target SIMs: 2')
            ->expectsOutput('Processed: 1')
            ->expectsOutput('Failed: 1')
            ->assertExitCode(1);
    }
}

