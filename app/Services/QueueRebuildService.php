<?php

namespace App\Services;

use App\Models\OutboundMessage;
use App\Models\Sim;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;

class QueueRebuildService
{
    private const DEFAULT_LOCK_TTL_SECONDS = 30;

    /**
     * @var \App\Services\RedisQueueService
     */
    protected $redisQueueService;

    /**
     * @param \App\Services\RedisQueueService $redisQueueService
     */
    public function __construct(RedisQueueService $redisQueueService)
    {
        $this->redisQueueService = $redisQueueService;
    }

    /**
     * Rebuild one SIM's Redis queues from DB pending truth.
     *
     * Flow:
     * 1) set rebuild lock
     * 2) clear SIM queues
     * 3) load DB rows with status=pending (only)
     * 4) re-enqueue message IDs by message_type tier mapping
     * 5) release lock in finally
     *
     * @param int $companyId
     * @param int $simId
     * @return array<string, int|string>
     */
    public function rebuildSimQueue(int $companyId, int $simId): array
    {
        if ($companyId <= 0) {
            throw new InvalidArgumentException('companyId must be a positive integer.');
        }

        if ($simId <= 0) {
            throw new InvalidArgumentException('simId must be a positive integer.');
        }

        $sim = Sim::query()
            ->where('id', $simId)
            ->where('company_id', $companyId)
            ->first();

        if ($sim === null) {
            throw new InvalidArgumentException('SIM does not belong to the provided company.');
        }

        if (!$this->acquireLock($simId)) {
            throw new RuntimeException('Queue rebuild already in progress for this SIM.');
        }

        $pendingCount = 0;
        $enqueuedCount = 0;
        $chatCount = 0;
        $followupCount = 0;
        $blastingCount = 0;

        try {
            $this->redisQueueService->clearSimQueues($simId);

            $pendingMessages = OutboundMessage::query()
                ->where('company_id', $companyId)
                ->where('sim_id', $simId)
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'message_type']);

            $pendingCount = $pendingMessages->count();

            foreach ($pendingMessages as $message) {
                $this->redisQueueService->enqueue(
                    $simId,
                    (int) $message->id,
                    (string) $message->message_type
                );

                $enqueuedCount++;

                $tier = $this->redisQueueService->tierForMessageType((string) $message->message_type);

                if ($tier === 'chat') {
                    $chatCount++;
                } elseif ($tier === 'followup') {
                    $followupCount++;
                } elseif ($tier === 'blasting') {
                    $blastingCount++;
                }
            }

            Log::info('SIM queue rebuild completed', [
                'company_id' => $companyId,
                'sim_id' => $simId,
                'pending_count' => $pendingCount,
                'enqueued_count' => $enqueuedCount,
                'chat_count' => $chatCount,
                'followup_count' => $followupCount,
                'blasting_count' => $blastingCount,
                'lock_key' => $this->lockKey($simId),
            ]);

            return [
                'company_id' => $companyId,
                'sim_id' => $simId,
                'pending_count' => $pendingCount,
                'enqueued_count' => $enqueuedCount,
                'chat_count' => $chatCount,
                'followup_count' => $followupCount,
                'blasting_count' => $blastingCount,
                'lock_key' => $this->lockKey($simId),
            ];
        } finally {
            $this->releaseLock($simId);
        }
    }

    /**
     * Build rebuild-lock key for SIM.
     *
     * @param int $simId
     * @return string
     */
    public function lockKey(int $simId): string
    {
        if ($simId <= 0) {
            throw new InvalidArgumentException('simId must be a positive integer.');
        }

        return "sms:lock:rebuild:sim:{$simId}";
    }

    /**
     * Acquire per-SIM rebuild lock using Redis NX+EX semantics.
     *
     * @param int $simId
     * @param int $ttlSeconds
     * @return bool
     */
    public function acquireLock(int $simId, int $ttlSeconds = self::DEFAULT_LOCK_TTL_SECONDS): bool
    {
        $safeTtl = max(1, $ttlSeconds);
        $result = Redis::set(
            $this->lockKey($simId),
            (string) now()->timestamp,
            'EX',
            $safeTtl,
            'NX'
        );

        return $result === true || $result === 'OK';
    }

    /**
     * Release per-SIM rebuild lock.
     *
     * @param int $simId
     * @return void
     */
    public function releaseLock(int $simId): void
    {
        Redis::del($this->lockKey($simId));
    }

    /**
     * Check whether rebuild lock currently exists for SIM.
     *
     * @param int $simId
     * @return bool
     */
    public function hasLock(int $simId): bool
    {
        return (int) Redis::exists($this->lockKey($simId)) > 0;
    }
}
