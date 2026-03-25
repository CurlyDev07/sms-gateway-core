<?php

namespace App\Services;

use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Log;

class OutboundRetryService
{
    /**
     * Handle outbound send failure by scheduling retry or marking final failed.
     *
     * @param \App\Models\OutboundMessage $message
     * @param string|null $error
     * @param string $source
     * @return void
     */
    public function handleSendFailure(OutboundMessage $message, ?string $error = null, string $source = 'send'): void
    {
        $maxAttempts = (int) config('services.gateway.outbound_retry_max_attempts', 3);

        $nextRetryCount = (int) $message->retry_count + 1;
        $reason = $error ?: 'Outbound send failed';

        if ($nextRetryCount < $maxAttempts) {
            $delaySeconds = $this->retryDelaySeconds($nextRetryCount);
            $nextAttemptAt = now()->addSeconds($delaySeconds);

            $message->update([
                'status' => 'pending',
                'retry_count' => $nextRetryCount,
                'failure_reason' => $reason,
                'failed_at' => now(),
                'scheduled_at' => $nextAttemptAt,
                'locked_at' => null,
            ]);

            Log::warning('Outbound retry scheduled', [
                'message_id' => $message->id,
                'company_id' => $message->company_id,
                'sim_id' => $message->sim_id,
                'retry_count' => $nextRetryCount,
                'next_attempt_at' => $nextAttemptAt->toDateTimeString(),
                'source' => $source,
            ]);

            return;
        }

        $finalReason = sprintf('Final failed after %d attempts: %s', $nextRetryCount, $reason);

        $message->update([
            'status' => 'failed',
            'retry_count' => $nextRetryCount,
            'failed_at' => now(),
            'failure_reason' => $finalReason,
            'scheduled_at' => null,
            'locked_at' => null,
        ]);

        Log::error('Outbound final failed', [
            'message_id' => $message->id,
            'company_id' => $message->company_id,
            'sim_id' => $message->sim_id,
            'retry_count' => $nextRetryCount,
            'source' => $source,
            'failure_reason' => $finalReason,
        ]);
    }

    /**
     * Determine if outbound message can still be retried.
     *
     * @param \App\Models\OutboundMessage $message
     * @return bool
     */
    public function canRetry(OutboundMessage $message): bool
    {
        $maxAttempts = (int) config('services.gateway.outbound_retry_max_attempts', 3);

        return ((int) $message->retry_count + 1) < $maxAttempts;
    }

    /**
     * Calculate exponential backoff delay with cap.
     *
     * @param int $attempt
     * @return int
     */
    protected function retryDelaySeconds(int $attempt): int
    {
        $baseDelay = (int) config('services.gateway.outbound_retry_base_delay_seconds', 30);
        $maxDelay = (int) config('services.gateway.outbound_retry_max_delay_seconds', 300);

        $safeAttempt = max(1, $attempt);
        $delay = $baseDelay * (2 ** ($safeAttempt - 1));

        return min($delay, $maxDelay);
    }
}
