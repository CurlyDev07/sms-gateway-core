<?php

namespace Tests\Feature\Commands;

use App\Services\QueueRebuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RebuildSimQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_runs_successfully_and_outputs_rebuild_counts(): void
    {
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')
            ->once()
            ->with(10, 20)
            ->andReturn([
                'company_id' => 10,
                'sim_id' => 20,
                'pending_count' => 9,
                'enqueued_count' => 9,
                'chat_count' => 4,
                'followup_count' => 3,
                'blasting_count' => 2,
                'lock_key' => 'sms:lock:rebuild:sim:20',
            ]);

        $this->app->instance(QueueRebuildService::class, $mock);

        $this->artisan('gateway:rebuild-sim-queue', [
            'company_id' => 10,
            'sim_id' => 20,
        ])->expectsOutput('SIM queue rebuild completed.')
            ->expectsOutput('Company ID: 10')
            ->expectsOutput('SIM ID: 20')
            ->expectsOutput('Pending rows found: 9')
            ->expectsOutput('Rows enqueued to Redis: 9')
            ->expectsOutput('Chat tier count: 4')
            ->expectsOutput('Followup tier count: 3')
            ->expectsOutput('Blasting tier count: 2')
            ->expectsOutput('Lock key: sms:lock:rebuild:sim:20')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_rejects_invalid_company_id_input(): void
    {
        $this->artisan('gateway:rebuild-sim-queue', [
            'company_id' => 0,
            'sim_id' => 20,
        ])->expectsOutput('Invalid company_id. Expected a positive integer.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_rejects_invalid_sim_id_input(): void
    {
        $this->artisan('gateway:rebuild-sim-queue', [
            'company_id' => 10,
            'sim_id' => 0,
        ])->expectsOutput('Invalid sim_id. Expected a positive integer.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_surfaces_company_or_sim_mismatch_failures_clearly(): void
    {
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')
            ->once()
            ->with(10, 20)
            ->andThrow(new InvalidArgumentException('SIM does not belong to the provided company.'));

        $this->app->instance(QueueRebuildService::class, $mock);

        $this->artisan('gateway:rebuild-sim-queue', [
            'company_id' => 10,
            'sim_id' => 20,
        ])->expectsOutput('SIM does not belong to the provided company.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_surfaces_lock_in_progress_failure_clearly(): void
    {
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')
            ->once()
            ->with(10, 20)
            ->andThrow(new RuntimeException('Queue rebuild already in progress for this SIM.'));

        $this->app->instance(QueueRebuildService::class, $mock);

        $this->artisan('gateway:rebuild-sim-queue', [
            'company_id' => 10,
            'sim_id' => 20,
        ])->expectsOutput('Queue rebuild already in progress for this SIM.')
            ->assertExitCode(1);
    }
}
