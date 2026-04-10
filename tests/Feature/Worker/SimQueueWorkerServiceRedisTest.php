<?php

namespace Tests\Feature\Worker;

use App\Contracts\SmsSenderInterface;
use App\DTO\SmsSendResult;
use App\Models\OutboundMessage;
use App\Models\Sim;
use App\Models\SimHealthLog;
use App\Services\OutboundRetryService;
use App\Services\QueueRebuildService;
use App\Services\RedisQueueService;
use App\Services\SimQueueWorkerService;
use App\Services\SimHealthService;
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
    public function failed_send_with_null_error_layer_routes_through_retry_path_and_returns_row_to_pending(): void
    {
        // null errorLayer (e.g. non-Python senders, test mocks) must always retry conservatively.
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
            ->andReturn(SmsSendResult::failed('SOME_ERROR', ['status' => 'failed']));

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('canSend')->once()->andReturn(true);
        $simState->shouldReceive('getSleepSecondsForMessageType')->once()->andReturn(1);
        $simState->shouldNotReceive('markSendSuccess');

        $retryService = Mockery::mock(OutboundRetryService::class)->makePartial();
        $retryService->shouldReceive('handleSendFailure')->once()->passthru();
        $retryService->shouldNotReceive('handlePermanentFailure');

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
        $this->assertSame('SOME_ERROR', $fresh->failure_reason);
    }

    /** @test */
    public function network_layer_failure_permanently_marks_message_as_failed_with_no_retry_scheduled(): void
    {
        // errorLayer='network' = Python-confirmed carrier/provider rejection.
        // Must NOT schedule a retry — message goes to status='failed' with no scheduled_at.
        Carbon::setTestNow(Carbon::parse('2026-04-04 17:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active', 'status' => 'active']);
        $message = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'queued',
            'scheduled_at' => null,
            'message_type' => 'CHAT',
            'retry_count' => 2,
        ]);

        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $smsSender->shouldReceive('send')
            ->once()
            ->andReturn(SmsSendResult::failed('SEND_FAILED', ['error_layer' => 'network'], 'network'));

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('canSend')->once()->andReturn(true);
        $simState->shouldReceive('getSleepSecondsForMessageType')->once()->andReturn(1);
        $simState->shouldNotReceive('markSendSuccess');

        $retryService = Mockery::mock(OutboundRetryService::class)->makePartial();
        $retryService->shouldReceive('handlePermanentFailure')->once()->passthru();
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
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(3, (int) $fresh->retry_count);
        $this->assertNull($fresh->locked_at);
        $this->assertNotNull($fresh->failed_at);
        $this->assertNull($fresh->scheduled_at);
        $this->assertSame('SEND_FAILED', $fresh->failure_reason);
    }

    /** @test */
    public function non_network_error_layer_failure_routes_to_retry_path(): void
    {
        // errorLayer='modem' (and hardware, transport, gateway, unknown) must still retry.
        // Only 'network' is terminal. Modem issues are temporary.
        Carbon::setTestNow(Carbon::parse('2026-04-04 18:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active', 'status' => 'active']);
        $message = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'queued',
            'scheduled_at' => null,
            'message_type' => 'CHAT',
            'retry_count' => 0,
        ]);

        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $smsSender->shouldReceive('send')
            ->once()
            ->andReturn(SmsSendResult::failed('MODEM_TIMEOUT', ['error_layer' => 'modem'], 'modem'));

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('canSend')->once()->andReturn(true);
        $simState->shouldReceive('getSleepSecondsForMessageType')->once()->andReturn(1);
        $simState->shouldNotReceive('markSendSuccess');

        $retryService = Mockery::mock(OutboundRetryService::class)->makePartial();
        $retryService->shouldReceive('handleSendFailure')->once()->passthru();
        $retryService->shouldNotReceive('handlePermanentFailure');

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
        $this->assertNotNull($fresh->scheduled_at);
    }

    /** @test */
    public function invalid_response_failure_is_non_retryable_and_marked_failed_without_reschedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10 13:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active', 'status' => 'active']);
        $message = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'queued',
            'scheduled_at' => null,
            'message_type' => 'CHAT',
            'retry_count' => 0,
        ]);

        $smsSender = Mockery::mock(SmsSenderInterface::class);
        $smsSender->shouldReceive('send')
            ->once()
            ->andReturn(SmsSendResult::failed('INVALID_RESPONSE', ['error_layer' => 'python_api'], 'python_api'));

        $simState = Mockery::mock(SimStateService::class);
        $simState->shouldReceive('canSend')->once()->andReturn(true);
        $simState->shouldReceive('getSleepSecondsForMessageType')->once()->andReturn(1);
        $simState->shouldNotReceive('markSendSuccess');

        $retryService = Mockery::mock(OutboundRetryService::class)->makePartial();
        $retryService->shouldReceive('handlePermanentFailure')->once()->passthru();
        $retryService->shouldNotReceive('handleSendFailure');

        $redisQueue = Mockery::mock(RedisQueueService::class);
        $redisQueue->shouldReceive('popNext')->once()->with($sim->id)->andReturn($message->id);

        $rebuild = Mockery::mock(QueueRebuildService::class);
        $rebuild->shouldReceive('hasLock')->once()->with($sim->id)->andReturn(false);

        $worker = new TestableSimQueueWorkerService(
            $smsSender,
            $simState,
            $retryService,
            $redisQueue,
            $rebuild,
            app(SimHealthService::class)
        );

        try {
            $worker->run($sim->id);
            $this->fail('Expected StopWorkerLoopException was not thrown.');
        } catch (StopWorkerLoopException $e) {
            $this->assertSame('stop-loop', $e->getMessage());
        }

        $fresh = $message->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(1, (int) $fresh->retry_count);
        $this->assertNull($fresh->scheduled_at);
        $this->assertNull($fresh->locked_at);
        $this->assertSame('INVALID_RESPONSE', $fresh->failure_reason);
        $this->assertSame('non_retryable', data_get($fresh->metadata, 'python_runtime.retry_decision.classification'));
        $this->assertFalse((bool) data_get($fresh->metadata, 'python_runtime.retry_decision.retryable'));
    }

    /** @test */
    public function repeated_runtime_timeouts_trigger_temporary_sim_runtime_suppression(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10 14:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'active',
            'status' => 'active',
            'mode' => 'NORMAL',
            'cooldown_until' => null,
        ]);

        $messages = [
            $this->createOutboundMessage([
                'company_id' => $company->id,
                'sim_id' => $sim->id,
                'status' => 'queued',
                'scheduled_at' => null,
                'message_type' => 'CHAT',
            ]),
            $this->createOutboundMessage([
                'company_id' => $company->id,
                'sim_id' => $sim->id,
                'status' => 'queued',
                'scheduled_at' => null,
                'message_type' => 'CHAT',
            ]),
            $this->createOutboundMessage([
                'company_id' => $company->id,
                'sim_id' => $sim->id,
                'status' => 'queued',
                'scheduled_at' => null,
                'message_type' => 'CHAT',
            ]),
        ];

        foreach ($messages as $message) {
            $smsSender = Mockery::mock(SmsSenderInterface::class);
            $smsSender->shouldReceive('send')
                ->once()
                ->andReturn(SmsSendResult::failed('RUNTIME_TIMEOUT', ['error_layer' => 'transport'], 'transport'));

            $simState = Mockery::mock(SimStateService::class);
            $simState->shouldReceive('canSend')->once()->andReturn(true);
            $simState->shouldReceive('getSleepSecondsForMessageType')->once()->andReturn(1);
            $simState->shouldNotReceive('markSendSuccess');

            $retryService = Mockery::mock(OutboundRetryService::class)->makePartial();
            $retryService->shouldReceive('handleSendFailure')->once()->passthru();
            $retryService->shouldNotReceive('handlePermanentFailure');

            $redisQueue = Mockery::mock(RedisQueueService::class);
            $redisQueue->shouldReceive('popNext')->once()->with($sim->id)->andReturn($message->id);

            $rebuild = Mockery::mock(QueueRebuildService::class);
            $rebuild->shouldReceive('hasLock')->once()->with($sim->id)->andReturn(false);

            $worker = new TestableSimQueueWorkerService(
                $smsSender,
                $simState,
                $retryService,
                $redisQueue,
                $rebuild,
                app(SimHealthService::class)
            );

            try {
                $worker->run($sim->id);
                $this->fail('Expected StopWorkerLoopException was not thrown.');
            } catch (StopWorkerLoopException $e) {
                $this->assertSame('stop-loop', $e->getMessage());
            }
        }

        $freshSim = $sim->fresh();
        $lastMessage = $messages[2]->fresh();

        $this->assertSame('COOLDOWN', $freshSim->mode);
        $this->assertNotNull($freshSim->cooldown_until);
        $this->assertTrue($freshSim->cooldown_until->greaterThan(now()));
        $this->assertSame('retryable', data_get($lastMessage->metadata, 'python_runtime.retry_decision.classification'));
        $this->assertTrue((bool) data_get($lastMessage->metadata, 'python_runtime.sim_runtime_control.suppressed'));

        $this->assertSame(3, SimHealthLog::query()->where('sim_id', $sim->id)->where('status', 'error')->count());
        $this->assertGreaterThanOrEqual(
            1,
            SimHealthLog::query()->where('sim_id', $sim->id)->where('status', 'cooldown')->count()
        );
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
