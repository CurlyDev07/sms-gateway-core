<?php

namespace Tests\Feature\Events;

use App\Events\SimOperatorStatusChanged;
use App\Services\QueueRebuildService;
use Mockery;
use Tests\TestCase;

class PausedSimResumeListenerTest extends TestCase
{
    /** @test */
    public function paused_to_active_triggers_queue_rebuild(): void
    {
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')
            ->once()
            ->with(1001, 2002)
            ->andReturn([
                'company_id' => 1001,
                'sim_id' => 2002,
                'pending_count' => 0,
                'enqueued_count' => 0,
                'chat_count' => 0,
                'followup_count' => 0,
                'blasting_count' => 0,
                'lock_key' => 'sms:lock:rebuild:sim:2002',
            ]);
        $this->app->instance(QueueRebuildService::class, $mock);

        event(new SimOperatorStatusChanged(2002, 1001, 'paused', 'active'));
    }

    /** @test */
    public function active_to_paused_does_not_trigger_queue_rebuild(): void
    {
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')->never();
        $this->app->instance(QueueRebuildService::class, $mock);

        event(new SimOperatorStatusChanged(22, 11, 'active', 'paused'));
    }

    /** @test */
    public function blocked_to_active_does_not_trigger_queue_rebuild(): void
    {
        $mock = Mockery::mock(QueueRebuildService::class);
        $mock->shouldReceive('rebuildSimQueue')->never();
        $this->app->instance(QueueRebuildService::class, $mock);

        event(new SimOperatorStatusChanged(33, 12, 'blocked', 'active'));
    }
}
