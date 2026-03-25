<?php

namespace App\Services;

use App\Jobs\RetryInboundRelayJob;
use App\Models\InboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InboundRelayRetryService
{
    /**
     * @var \App\Services\InboundRelayService
     */
    protected $inboundRelayService;

    /**
     * @param \App\Services\InboundRelayService $inboundRelayService
     */
    public function __construct(InboundRelayService $inboundRelayService)
    {
        $this->inboundRelayService = $inboundRelayService;
    }

    /**
     * Process relay with retry/final-failure handling.
     *
     * @param \App\Models\InboundMessage $message
     * @return bool
     */
    public function process(InboundMessage $message): bool
    {
        $locked = $this->acquireRelayLock((int) $message->id);

        if ($locked === null) {
            return false;
        }

        $success = $this->inboundRelayService->relay($locked);

        if ($success) {
            $this->clearRetryState((int) $locked->id);
            return true;
        }

        return $this->handleRelayFailure((int) $locked->id);
    }

    /**
     * Dispatch due relay retries.
     *
     * @param int $limit
     * @return int
     */
    public function dispatchDueRetries(int $limit = 100): int
    {
        $lockSeconds = (int) config('services.gateway.inbound_relay_lock_seconds', 120);
        $activeLockCutoff = now()->subSeconds($lockSeconds);

        $candidateIds = InboundMessage::query()
            ->where('relay_status', 'pending')
            ->whereNotNull('relay_next_attempt_at')
            ->where('relay_next_attempt_at', '<=', now())
            ->where(function ($q) use ($activeLockCutoff) {
                $q->whereNull('relay_locked_at')
                    ->orWhere('relay_locked_at', '<=', $activeLockCutoff);
            })
            ->orderBy('relay_next_attempt_at')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;

        foreach ($candidateIds as $id) {
            if (!$this->claimDueRetryForDispatch((int) $id, $activeLockCutoff)) {
                continue;
            }

            RetryInboundRelayJob::dispatch((int) $id);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * @param int $messageId
     * @return \App\Models\InboundMessage|null
     */
    protected function acquireRelayLock(int $messageId): ?InboundMessage
    {
        return DB::transaction(function () use ($messageId) {
            $message = InboundMessage::query()->lockForUpdate()->find($messageId);

            if ($message === null) {
                return null;
            }

            if ($message->relay_status === 'success') {
                return null;
            }

            $lockSeconds = (int) config('services.gateway.inbound_relay_lock_seconds', 120);
            $activeLockCutoff = now()->subSeconds($lockSeconds);

            if ($message->relay_locked_at !== null && $message->relay_locked_at->greaterThan($activeLockCutoff)) {
                return null;
            }

            if ($message->relay_next_attempt_at !== null && $message->relay_next_attempt_at->isFuture()) {
                return null;
            }

            $message->update([
                'relay_locked_at' => now(),
                'relay_status' => 'pending',
            ]);

            return $message->fresh();
        });
    }

    /**
     * @param int $messageId
     * @return bool
     */
    protected function handleRelayFailure(int $messageId): bool
    {
        $maxAttempts = (int) config('services.gateway.inbound_relay_retry_max_attempts', 3);

        return DB::transaction(function () use ($messageId, $maxAttempts) {
            $message = InboundMessage::query()->lockForUpdate()->find($messageId);

            if ($message === null) {
                return false;
            }

            $nextRetryCount = (int) $message->relay_retry_count + 1;

            if ($nextRetryCount < $maxAttempts) {
                $delaySeconds = $this->retryDelaySeconds($nextRetryCount);
                $nextAttemptAt = now()->addSeconds($delaySeconds);

                $message->update([
                    'relay_retry_count' => $nextRetryCount,
                    'relay_next_attempt_at' => $nextAttemptAt,
                    'relay_locked_at' => null,
                    'relay_status' => 'pending',
                ]);

                Log::warning('Inbound relay retry scheduled', [
                    'inbound_message_id' => $message->id,
                    'retry_count' => $nextRetryCount,
                    'next_attempt_at' => $nextAttemptAt->toDateTimeString(),
                ]);

                return false;
            }

            $message->update([
                'relay_retry_count' => $nextRetryCount,
                'relay_status' => 'failed',
                'relay_failed_at' => now(),
                'relay_next_attempt_at' => null,
                'relay_locked_at' => null,
            ]);

            Log::error('Inbound relay final failed', [
                'inbound_message_id' => $message->id,
                'retry_count' => $nextRetryCount,
                'relay_error' => $message->relay_error,
            ]);

            return false;
        });
    }

    /**
     * @param int $messageId
     * @return void
     */
    protected function clearRetryState(int $messageId): void
    {
        InboundMessage::query()
            ->where('id', $messageId)
            ->update([
                'relay_retry_count' => 0,
                'relay_next_attempt_at' => null,
                'relay_failed_at' => null,
                'relay_locked_at' => null,
            ]);
    }

    /**
     * @param int $attempt
     * @return int
     */
    protected function retryDelaySeconds(int $attempt): int
    {
        $baseDelay = (int) config('services.gateway.inbound_relay_retry_base_delay_seconds', 30);
        $maxDelay = (int) config('services.gateway.inbound_relay_retry_max_delay_seconds', 300);

        $safeAttempt = max(1, $attempt);
        $delay = $baseDelay * (2 ** ($safeAttempt - 1));

        return min($delay, $maxDelay);
    }

    /**
     * Claim a due retry row so command dispatch does not enqueue duplicates.
     *
     * @param int $messageId
     * @param \Illuminate\Support\Carbon $activeLockCutoff
     * @return bool
     */
    protected function claimDueRetryForDispatch(int $messageId, $activeLockCutoff): bool
    {
        return DB::transaction(function () use ($messageId, $activeLockCutoff) {
            $message = InboundMessage::query()->lockForUpdate()->find($messageId);

            if ($message === null) {
                return false;
            }

            if ($message->relay_status !== 'pending') {
                return false;
            }

            if ($message->relay_next_attempt_at === null || $message->relay_next_attempt_at->isFuture()) {
                return false;
            }

            if ($message->relay_locked_at !== null && $message->relay_locked_at->greaterThan($activeLockCutoff)) {
                return false;
            }

            // Clear due marker so the same row is not redispatched by another selector.
            $message->update([
                'relay_next_attempt_at' => null,
            ]);

            return true;
        });
    }
}
