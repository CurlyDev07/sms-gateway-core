<?php

namespace App\Services;

use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Log;

class OutboundRetryService
{
    private const RETRY_DELAY_MINUTES = 5;

    /**
     * Handle outbound send failure by scheduling fixed-interval retry.
     *
     * @param \App\Models\OutboundMessage $message
     * @param string|null $error
     * @param string $source
     * @return void
     */
    public function handleSendFailure(OutboundMessage $message, ?string $error = null, string $source = 'send'): void
    {
        $nextRetryCount = (int) $message->retry_count + 1;
        $reason = $error ?: 'Outbound send failed';
        $nextAttemptAt = now()->addMinutes(self::RETRY_DELAY_MINUTES);

        $message->update([
            'status' => 'pending',
            'retry_count' => $nextRetryCount,
            'failed_at' => now(),
            'failure_reason' => $reason,
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
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Handle a permanent carrier/provider rejection with no retry scheduled.
     *
     * Use this when the errorLayer is 'network' (Python-confirmed carrier rejection).
     * The message is marked failed and will not be retried automatically.
     * Operator may manually intervene or migrate the message.
     *
     * @param \App\Models\OutboundMessage $message
     * @param string|null $error
     * @param string $source
     * @return void
     */
    public function handlePermanentFailure(OutboundMessage $message, ?string $error = null, string $source = 'send'): void
    {
        $nextRetryCount = (int) $message->retry_count + 1;
        $reason = $error ?: 'Permanent carrier rejection';

        $message->update([
            'status' => 'failed',
            'retry_count' => $nextRetryCount,
            'failed_at' => now(),
            'failure_reason' => $reason,
            'scheduled_at' => null,
            'locked_at' => null,
        ]);

        Log::warning('Outbound permanent failure (no retry scheduled)', [
            'message_id' => $message->id,
            'company_id' => $message->company_id,
            'sim_id' => $message->sim_id,
            'retry_count' => $nextRetryCount,
            'source' => $source,
            'failure_reason' => $reason,
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
        return true;
    }
}
