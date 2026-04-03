<?php

namespace Tests\Unit\Services;

use App\Models\OutboundMessage;
use App\Services\QueueRebuildService;
use App\Services\RedisQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class QueueRebuildServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_rebuilds_pending_only_rows_for_one_sim_and_counts_tiers_correctly(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        $otherSim = $this->createSim($company);

        $pendingChat = $this->createOutboundMessage($company->id, $sim->id, 'CHAT', 'pending');
        $pendingAutoReply = $this->createOutboundMessage($company->id, $sim->id, 'AUTO_REPLY', 'pending');
        $pendingFollowUp = $this->createOutboundMessage($company->id, $sim->id, 'FOLLOW_UP', 'pending');
        $pendingBlast = $this->createOutboundMessage($company->id, $sim->id, 'BLAST', 'pending');

        $this->createOutboundMessage($company->id, $sim->id, 'CHAT', 'queued');
        $this->createOutboundMessage($company->id, $sim->id, 'CHAT', 'sending');
        $this->createOutboundMessage($company->id, $sim->id, 'CHAT', 'sent');
        $this->createOutboundMessage($company->id, $sim->id, 'CHAT', 'failed');
        $this->createOutboundMessage($company->id, $sim->id, 'CHAT', 'cancelled');
        $this->createOutboundMessage($company->id, $otherSim->id, 'CHAT', 'pending');

        $enqueued = [];

        $redisQueueService = Mockery::mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('clearSimQueues')
            ->once()
            ->with($sim->id);

        $redisQueueService->shouldReceive('enqueue')
            ->times(4)
            ->andReturnUsing(function (int $simId, int $messageId, string $messageType) use (&$enqueued): void {
                $enqueued[] = [
                    'sim_id' => $simId,
                    'message_id' => $messageId,
                    'message_type' => $messageType,
                ];
            });

        $redisQueueService->shouldReceive('tierForMessageType')
            ->times(4)
            ->andReturnUsing(function (string $messageType): string {
                if ($messageType === 'CHAT' || $messageType === 'AUTO_REPLY') {
                    return 'chat';
                }

                if ($messageType === 'FOLLOW_UP') {
                    return 'followup';
                }

                if ($messageType === 'BLAST') {
                    return 'blasting';
                }

                throw new InvalidArgumentException('Unsupported message type for test: '.$messageType);
            });

        Redis::shouldReceive('set')->once()->andReturn('OK');
        Redis::shouldReceive('del')->once()->andReturn(1);

        $service = new QueueRebuildService($redisQueueService);
        $result = $service->rebuildSimQueue($company->id, $sim->id);

        $this->assertSame(4, $result['pending_count']);
        $this->assertSame(4, $result['enqueued_count']);
        $this->assertSame(2, $result['chat_count']);
        $this->assertSame(1, $result['followup_count']);
        $this->assertSame(1, $result['blasting_count']);
        $this->assertSame("sms:lock:rebuild:sim:{$sim->id}", $result['lock_key']);

        $this->assertCount(4, $enqueued);
        $this->assertEqualsCanonicalizing(
            [$pendingChat->id, $pendingAutoReply->id, $pendingFollowUp->id, $pendingBlast->id],
            array_column($enqueued, 'message_id')
        );

        foreach ($enqueued as $entry) {
            $this->assertSame($sim->id, $entry['sim_id']);
        }
    }

    /** @test */
    public function it_rejects_cross_company_sim_mismatch(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPQRA']);
        $companyB = $this->createCompany(['code' => 'CMPQRB']);
        $sim = $this->createSim($companyA);

        $redisQueueService = Mockery::mock(RedisQueueService::class);
        $redisQueueService->shouldNotReceive('clearSimQueues');
        $redisQueueService->shouldNotReceive('enqueue');

        $service = new QueueRebuildService($redisQueueService);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SIM does not belong to the provided company.');

        $service->rebuildSimQueue($companyB->id, $sim->id);
    }

    /** @test */
    public function it_fails_when_rebuild_lock_is_already_held_and_does_not_rebuild(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $redisQueueService = Mockery::mock(RedisQueueService::class);
        $redisQueueService->shouldNotReceive('clearSimQueues');
        $redisQueueService->shouldNotReceive('enqueue');

        Redis::shouldReceive('set')->once()->andReturn(null);

        $service = new QueueRebuildService($redisQueueService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Queue rebuild already in progress for this SIM.');

        $service->rebuildSimQueue($company->id, $sim->id);
    }

    /** @test */
    public function it_always_releases_lock_even_when_rebuild_fails_midway(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $this->createOutboundMessage($company->id, $sim->id, 'CHAT', 'pending');

        $redisQueueService = Mockery::mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('clearSimQueues')->once()->with($sim->id);
        $redisQueueService->shouldReceive('enqueue')
            ->once()
            ->andThrow(new RuntimeException('enqueue failure')); 

        Redis::shouldReceive('set')->once()->andReturn('OK');
        Redis::shouldReceive('del')->once()->andReturn(1);

        $service = new QueueRebuildService($redisQueueService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('enqueue failure');

        $service->rebuildSimQueue($company->id, $sim->id);
    }

    private function createOutboundMessage(
        int $companyId,
        int $simId,
        string $messageType,
        string $status
    ): OutboundMessage {
        return OutboundMessage::query()->create([
            'company_id' => $companyId,
            'sim_id' => $simId,
            'customer_phone' => '09175550000'.random_int(0, 9),
            'message' => 'Queue rebuild test '.$status,
            'message_type' => $messageType,
            'priority' => 100,
            'status' => $status,
        ]);
    }
}
