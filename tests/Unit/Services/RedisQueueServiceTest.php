<?php

namespace Tests\Unit\Services;

use App\Services\RedisQueueService;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Tests\TestCase;

class RedisQueueServiceTest extends TestCase
{
    /** @test */
    public function it_builds_queue_keys_with_expected_format(): void
    {
        $service = new RedisQueueService();

        $this->assertSame('sms:queue:sim:42:chat', $service->queueKey(42, 'chat'));
        $this->assertSame('sms:queue:sim:42:followup', $service->queueKey(42, 'followup'));
        $this->assertSame('sms:queue:sim:42:blasting', $service->queueKey(42, 'blasting'));
    }

    /** @test */
    public function it_maps_message_types_to_expected_tiers(): void
    {
        $service = new RedisQueueService();

        $this->assertSame('chat', $service->tierForMessageType('CHAT'));
        $this->assertSame('chat', $service->tierForMessageType('AUTO_REPLY'));
        $this->assertSame('followup', $service->tierForMessageType('FOLLOW_UP'));
        $this->assertSame('blasting', $service->tierForMessageType('BLAST'));
    }

    /** @test */
    public function it_pops_in_priority_order_chat_followup_then_blasting(): void
    {
        $service = new RedisQueueService();
        $simId = 7;

        Redis::shouldReceive('lpop')
            ->once()
            ->with('sms:queue:sim:7:chat')
            ->andReturn(null);
        Redis::shouldReceive('lpop')
            ->once()
            ->with('sms:queue:sim:7:followup')
            ->andReturn('123');

        $messageId = $service->popNext($simId);

        $this->assertSame(123, $messageId);
    }

    /** @test */
    public function depth_returns_total_and_per_tier_counts(): void
    {
        $service = new RedisQueueService();
        $simId = 9;

        Redis::shouldReceive('llen')
            ->once()
            ->with('sms:queue:sim:9:chat')
            ->andReturn(5);
        $this->assertSame(5, $service->depth($simId, 'chat'));

        Redis::shouldReceive('llen')
            ->once()
            ->with('sms:queue:sim:9:chat')
            ->andReturn(2);
        Redis::shouldReceive('llen')
            ->once()
            ->with('sms:queue:sim:9:followup')
            ->andReturn(3);
        Redis::shouldReceive('llen')
            ->once()
            ->with('sms:queue:sim:9:blasting')
            ->andReturn(4);

        $this->assertSame(9, $service->depth($simId));
    }

    /** @test */
    public function clear_sim_queues_clears_all_three_keys(): void
    {
        $service = new RedisQueueService();
        $simId = 11;

        Redis::shouldReceive('del')->once()->with('sms:queue:sim:11:chat');
        Redis::shouldReceive('del')->once()->with('sms:queue:sim:11:followup');
        Redis::shouldReceive('del')->once()->with('sms:queue:sim:11:blasting');

        $service->clearSimQueues($simId);

        $this->assertTrue(true);
    }

    /** @test */
    public function it_rejects_invalid_queue_tier(): void
    {
        $service = new RedisQueueService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported queue tier');

        $service->queueKey(1, 'invalid-tier');
    }

    /** @test */
    public function it_rejects_invalid_message_type(): void
    {
        $service = new RedisQueueService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported message type');

        $service->tierForMessageType('INVALID_TYPE');
    }
}

