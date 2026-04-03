<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

class RedisQueueService
{
    private const TIER_CHAT = 'chat';
    private const TIER_FOLLOWUP = 'followup';
    private const TIER_BLASTING = 'blasting';

    /**
     * Priority order for per-SIM queue pop.
     *
     * @var array<int, string>
     */
    private const PRIORITY_ORDER = [
        self::TIER_CHAT,
        self::TIER_FOLLOWUP,
        self::TIER_BLASTING,
    ];

    /**
     * Message type to queue tier mapping.
     *
     * @var array<string, string>
     */
    private const MESSAGE_TYPE_TO_TIER = [
        'CHAT' => self::TIER_CHAT,
        'AUTO_REPLY' => self::TIER_CHAT,
        'FOLLOW_UP' => self::TIER_FOLLOWUP,
        'BLAST' => self::TIER_BLASTING,
    ];

    /**
     * Build queue key for a SIM + tier.
     *
     * @param int $simId
     * @param string $tier
     * @return string
     */
    public function queueKey(int $simId, string $tier): string
    {
        $normalizedTier = $this->normalizeTier($tier);
        $this->assertValidSimId($simId);

        return "sms:queue:sim:{$simId}:{$normalizedTier}";
    }

    /**
     * Resolve queue tier for outbound message type.
     *
     * @param string $messageType
     * @return string
     */
    public function tierForMessageType(string $messageType): string
    {
        $normalizedType = strtoupper(trim($messageType));

        if (!array_key_exists($normalizedType, self::MESSAGE_TYPE_TO_TIER)) {
            throw new InvalidArgumentException('Unsupported message type: '.$messageType);
        }

        return self::MESSAGE_TYPE_TO_TIER[$normalizedType];
    }

    /**
     * Enqueue one outbound message ID into its mapped tier queue.
     *
     * @param int $simId
     * @param int $messageId
     * @param string $messageType
     * @return void
     */
    public function enqueue(int $simId, int $messageId, string $messageType): void
    {
        $this->assertValidMessageId($messageId);

        $tier = $this->tierForMessageType($messageType);
        $key = $this->queueKey($simId, $tier);

        Redis::rpush($key, (string) $messageId);
    }

    /**
     * Pop next outbound message ID for a SIM using strict tier priority order.
     *
     * @param int $simId
     * @return int|null
     */
    public function popNext(int $simId): ?int
    {
        foreach (self::PRIORITY_ORDER as $tier) {
            $key = $this->queueKey($simId, $tier);
            $value = Redis::lpop($key);

            if ($value === null) {
                continue;
            }

            $messageId = (int) $value;

            if ($messageId > 0) {
                return $messageId;
            }
        }

        return null;
    }

    /**
     * Get queue depth for one tier or total depth across all tiers.
     *
     * @param int $simId
     * @param string|null $tier
     * @return int
     */
    public function depth(int $simId, ?string $tier = null): int
    {
        if ($tier !== null) {
            return (int) Redis::llen($this->queueKey($simId, $tier));
        }

        $total = 0;

        foreach ($this->allQueueKeys($simId) as $key) {
            $total += (int) Redis::llen($key);
        }

        return $total;
    }

    /**
     * Clear all queue tiers for a specific SIM.
     *
     * @param int $simId
     * @return void
     */
    public function clearSimQueues(int $simId): void
    {
        foreach ($this->allQueueKeys($simId) as $key) {
            Redis::del($key);
        }
    }

    /**
     * Return all per-SIM queue keys in priority order.
     *
     * @param int $simId
     * @return array<int, string>
     */
    public function allQueueKeys(int $simId): array
    {
        $keys = [];

        foreach (self::PRIORITY_ORDER as $tier) {
            $keys[] = $this->queueKey($simId, $tier);
        }

        return $keys;
    }

    /**
     * @param string $tier
     * @return string
     */
    protected function normalizeTier(string $tier): string
    {
        $normalized = strtolower(trim($tier));

        if (!in_array($normalized, self::PRIORITY_ORDER, true)) {
            throw new InvalidArgumentException('Unsupported queue tier: '.$tier);
        }

        return $normalized;
    }

    /**
     * @param int $simId
     * @return void
     */
    protected function assertValidSimId(int $simId): void
    {
        if ($simId <= 0) {
            throw new InvalidArgumentException('simId must be a positive integer.');
        }
    }

    /**
     * @param int $messageId
     * @return void
     */
    protected function assertValidMessageId(int $messageId): void
    {
        if ($messageId <= 0) {
            throw new InvalidArgumentException('messageId must be a positive integer.');
        }
    }
}
