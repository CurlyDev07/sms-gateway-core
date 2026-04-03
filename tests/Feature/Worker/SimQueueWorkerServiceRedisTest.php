<?php

namespace Tests\Feature\Worker;

use App\Contracts\SmsSenderInterface;
use App\DTO\SmsSendResult;
use App\Models\OutboundMessage;
use App\Models\Sim;
use App\Services\OutboundRetryService;
use App\Services\QueueRebuildService;
use App\Services\RedisQueueService;
use App\Services\SimQueueWorkerService;
use App\Services\SimStateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class StopWorkerLoopException extends \RuntimeException
{
}

class TestableSimQueueWorkerService extends SimQueueWorkerService
{
    protected function sleepSeconds(int $seconds): void
    {
        throw new StopWorkerLoopException('stop-loop');
    }

    public function claimForTest(Sim $sim, int $messageId): ?OutboundMessage
    {
        return $this->claimPoppedMessage($sim, $messageId);
    }
}

class SimQueueWorkerServiceRedisTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function rebuild_lock_present_worker_skips_before_pop(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active', 'status' => 'active']);

        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $smsSender->shouldNotReceive('send');

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('canSend')->once()->withArgs(function (Sim $arg) use ($sim) {
            return (int) $arg->id === (int) $sim->id;
        })->andReturn(true);
        $simState->shouldReceive('getIdleSleepSeconds')->once()->andReturn(1);

        $retryService = Mockery::mock(OutboundRetryService::class);
        $retryService->shouldNotReceive('handleSendFailure');

        $redisQueue = Mockery::mock(RedisQueueService::class);
        $redisQueue->shouldNotReceive('popNext');

        $rebuild = Mockery::mock(QueueRebuildService::class);
        $rebuild->shouldReceive('hasLock')->once()->with($sim->id)->andReturn(true);

        $worker = new TestableSimQueueWorkerService($smsSender, $simState, $retryService, $redisQueue, $rebuild);

        try {
            $worker->run($sim->id);
            $this->fail('Expected StopWorkerLoopException was not thrown.');
        } catch (StopWorkerLoopException $e) {
            $this->assertSame('stop-loop', $e->getMessage());
        }
    }

    /** @test */
    public function paused_sim_worker_skips_processing(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'paused', 'status' => 'active']);

        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $smsSender->shouldNotReceive('send');

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('getInactiveSleepSeconds')->once()->andReturn(1);
        $simState->shouldNotReceive('canSend');

        $retryService = Mockery::mock(OutboundRetryService::class);
        $retryService->shouldNotReceive('handleSendFailure');

        $redisQueue = Mockery::mock(RedisQueueService::class);
        $redisQueue->shouldNotReceive('popNext');

        $rebuild = Mockery::mock(QueueRebuildService::class);
        $rebuild->shouldNotReceive('hasLock');

        $worker = new TestableSimQueueWorkerService($smsSender, $simState, $retryService, $redisQueue, $rebuild);

        try {
            $worker->run($sim->id);
            $this->fail('Expected StopWorkerLoopException was not thrown.');
        } catch (StopWorkerLoopException $e) {
            $this->assertSame('stop-loop', $e->getMessage());
        }
    }

    /** @test */
    public function blocked_sim_is_not_auto_skipped_solely_because_of_operator_status(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'blocked', 'status' => 'active']);

        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $smsSender->shouldNotReceive('send');

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('canSend')->once()->andReturn(true);
        $simState->shouldReceive('getIdleSleepSeconds')->once()->andReturn(1);

        $retryService = Mockery::mock(OutboundRetryService::class);
        $retryService->shouldNotReceive('handleSendFailure');

        $redisQueue = Mockery::mock(RedisQueueService::class);
        $redisQueue->shouldReceive('popNext')->once()->with($sim->id)->andReturn(null);

        $rebuild = Mockery::mock(QueueRebuildService::class);
        $rebuild->shouldReceive('hasLock')->once()->with($sim->id)->andReturn(false);

        $worker = new TestableSimQueueWorkerService($smsSender, $simState, $retryService, $redisQueue, $rebuild);

        try {
            $worker->run($sim->id);
            $this->fail('Expected StopWorkerLoopException was not thrown.');
        } catch (StopWorkerLoopException $e) {
            $this->assertSame('stop-loop', $e->getMessage());
        }
    }

    /** @test */
    public function stale_redis_id_is_safely_dropped(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $worker = $this->buildWorkerForClaimTests();

        $claimed = $worker->claimForTest($sim, 999999);

        $this->assertNull($claimed);
    }

    /** @test */
    public function db_truth_recheck_prevents_invalid_send_when_popped_id_is_no_longer_eligible(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 14:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $futureQueued = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'queued',
            'scheduled_at' => now()->addMinutes(10),
        ]);

        $worker = $this->buildWorkerForClaimTests();
        $claimed = $worker->claimForTest($sim, (int) $futureQueued->id);

        $this->assertNull($claimed);
        $this->assertSame('queued', $futureQueued->fresh()->status);
        $this->assertNull($futureQueued->fresh()->locked_at);
    }

    /** @test */
    public function queued_row_is_claimed_as_sending_before_send_and_success_transitions_to_sent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 15:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active', 'status' => 'active']);
        $message = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'queued',
            'scheduled_at' => null,
            'message_type' => 'CHAT',
        ]);

        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $smsSender->shouldReceive('send')
            ->once()
            ->andReturnUsing(function () use ($message): SmsSendResult {
                $duringSend = $message->fresh();
                $this->assertSame('sending', $duringSend->status);
                $this->assertNotNull($duringSend->locked_at);

                return SmsSendResult::success('provider-123', ['status' => 'success']);
            });

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('canSend')->once()->withArgs(function (Sim $arg) use ($sim) {
            return (int) $arg->id === (int) $sim->id;
        })->andReturn(true);
        $simState->shouldReceive('markSendSuccess')->once()->withArgs(function (Sim $arg, string $messageType) use ($sim) {
            return (int) $arg->id === (int) $sim->id && $messageType === 'CHAT';
        });
        $simState->shouldReceive('getSleepSecondsForMessageType')->once()->andReturn(1);

        $retryService = Mockery::mock(OutboundRetryService::class);
        $retryService->shouldNotReceive('handleSendFailure');

        $redisQueue = Mockery::mock(RedisQueueService::class);
        $redisQueue->shouldReceive('popNext')->once()->with($sim->id)->andReturn($message->id);

        $rebuild = Mockery::mock(QueueRebuildService::class);
        $rebuild->shouldReceive('hasLock')->once()->with($sim->id)->andReturn(false);

        $worker = new TestableSimQueueWorkerService($smsSender, $simState, $retryService, $redisQueue, $rebuild);

        try {
            $worker->run($sim->id);
            $this->fail('Expected StopWorkerLoopException was not thrown.');
        } catch (StopWorkerLoopException $e) {
            $this->assertSame('stop-loop', $e->getMessage());
        }

        $fresh = $message->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertNull($fresh->locked_at);
        $this->assertNull($fresh->failure_reason);
        $this->assertNull($fresh->failed_at);
        $this->assertNull($fresh->scheduled_at);
    }

    /** @test */
    public function failed_send_routes_through_retry_path_and_returns_row_to_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 16:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active', 'status' => 'active']);
        $message = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'queued',
            'scheduled_at' => null,
            'message_type' => 'FOLLOW_UP',
            'retry_count' => 0,
        ]);

        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $smsSender->shouldReceive('send')
            ->once()
            ->andReturn(SmsSendResult::failed('NETWORK_ERROR', ['status' => 'failed']));

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('canSend')->once()->andReturn(true);
        $simState->shouldReceive('getSleepSecondsForMessageType')->once()->andReturn(1);
        $simState->shouldNotReceive('markSendSuccess');

        $retryService = Mockery::mock(OutboundRetryService::class)->makePartial();
        $retryService->shouldReceive('handleSendFailure')->once()->passthru();

        $redisQueue = Mockery::mock(RedisQueueService::class);
        $redisQueue->shouldReceive('popNext')->once()->with($sim->id)->andReturn($message->id);

        $rebuild = Mockery::mock(QueueRebuildService::class);
        $rebuild->shouldReceive('hasLock')->once()->with($sim->id)->andReturn(false);

        $worker = new TestableSimQueueWorkerService($smsSender, $simState, $retryService, $redisQueue, $rebuild);

        try {
            $worker->run($sim->id);
            $this->fail('Expected StopWorkerLoopException was not thrown.');
        } catch (StopWorkerLoopException $e) {
            $this->assertSame('stop-loop', $e->getMessage());
        }

        $fresh = $message->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(1, (int) $fresh->retry_count);
        $this->assertNull($fresh->locked_at);
        $this->assertNotNull($fresh->failed_at);
        $this->assertNotNull($fresh->scheduled_at);
        $this->assertSame('NETWORK_ERROR', $fresh->failure_reason);
    }

    private function buildWorkerForClaimTests(): TestableSimQueueWorkerService
    {
        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $simState = Mockery::mock(SimStateService::class);
        $retryService = app(OutboundRetryService::class);
        $redisQueue = Mockery::mock(RedisQueueService::class);
        $rebuild = Mockery::mock(QueueRebuildService::class);

        return new TestableSimQueueWorkerService($smsSender, $simState, $retryService, $redisQueue, $rebuild);
    }

    private function createOutboundMessage(array $attributes): OutboundMessage
    {
        return OutboundMessage::query()->create(array_merge([
            'customer_phone' => '09179990000',
            'message' => 'worker-redis-test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'pending',
            'retry_count' => 0,
        ], $attributes));
    }
}

