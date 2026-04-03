<?php

namespace Tests\Feature\Commands;

use App\Models\OutboundMessage;
use App\Services\RedisQueueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class RetrySchedulerCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function due_pending_rows_are_claimed_and_enqueued_while_paused_rows_are_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 10:00:00'));

        $company = $this->createCompany();
        $activeSim = $this->createSim($company, ['operator_status' => 'active']);
        $pausedSim = $this->createSim($company, ['operator_status' => 'paused']);

        $activeDue = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $activeSim->id,
            'status' => 'pending',
            'scheduled_at' => now()->subMinutes(5),
            'message_type' => 'CHAT',
        ]);

        $pausedDue = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'status' => 'pending',
            'scheduled_at' => now()->subMinutes(5),
            'message_type' => 'CHAT',
        ]);

        $notDue = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $activeSim->id,
            'status' => 'pending',
            'scheduled_at' => now()->addMinutes(5),
            'message_type' => 'FOLLOW_UP',
        ]);

        $mock = Mockery::mock(RedisQueueService::class);
        $mock->shouldReceive('enqueue')
            ->once()
            ->with($activeSim->id, $activeDue->id, 'CHAT');
        $this->app->instance(RedisQueueService::class, $mock);

        $this->artisan('gateway:retry-scheduler', ['--limit' => 10])
            ->expectsOutput('Retry scheduler run completed.')
            ->expectsOutput('Due rows scanned: 1')
            ->expectsOutput('Eligible rows claimed: 1')
            ->expectsOutput('Enqueued: 1')
            ->expectsOutput('Skipped: 0')
            ->expectsOutput('Enqueue failures: 0')
            ->assertExitCode(0);

        $activeDueFresh = $activeDue->fresh();
        $this->assertSame('queued', $activeDueFresh->status);
        $this->assertNotNull($activeDueFresh->queued_at);
        $this->assertNull($activeDueFresh->scheduled_at);

        $pausedDueFresh = $pausedDue->fresh();
        $this->assertSame('pending', $pausedDueFresh->status);
        $this->assertNull($pausedDueFresh->queued_at);
        $this->assertNotNull($pausedDueFresh->scheduled_at);

        $this->assertSame('pending', $notDue->fresh()->status);
    }

    /** @test */
    public function invalid_limit_is_rejected(): void
    {
        $this->artisan('gateway:retry-scheduler', ['--limit' => 0])
            ->expectsOutput('Invalid --limit value. Expected a positive integer.')
            ->assertExitCode(1);
    }

    /** @test */
    public function enqueue_failure_restores_row_to_pending_with_scheduled_at_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 11:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $due = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'pending',
            'scheduled_at' => now()->subMinutes(1),
            'message_type' => 'BLAST',
        ]);

        $mock = Mockery::mock(RedisQueueService::class);
        $mock->shouldReceive('enqueue')
            ->once()
            ->with($sim->id, $due->id, 'BLAST')
            ->andThrow(new RuntimeException('Redis unavailable'));
        $this->app->instance(RedisQueueService::class, $mock);

        $this->artisan('gateway:retry-scheduler', ['--limit' => 10])
            ->expectsOutput('Retry scheduler run completed.')
            ->expectsOutput('Enqueue failures: 1')
            ->assertExitCode(1);

        $fresh = $due->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->queued_at);
        $this->assertNotNull($fresh->scheduled_at);
        $this->assertSame('2026-04-04 11:00:00', $fresh->scheduled_at->format('Y-m-d H:i:s'));
    }

    private function createOutboundMessage(array $attributes): OutboundMessage
    {
        return OutboundMessage::query()->create(array_merge([
            'customer_phone' => '09179000000',
            'message' => 'retry-scheduler-test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'pending',
        ], $attributes));
    }
}

