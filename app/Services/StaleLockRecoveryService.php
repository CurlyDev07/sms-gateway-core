<?php

namespace App\Services;

use App\Models\OutboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StaleLockRecoveryService
{
    /**
     * @var \App\Services\OutboundRetryService
     */
    protected $outboundRetryService;

    /**
     * @param \App\Services\OutboundRetryService $outboundRetryService
     */
    public function __construct(OutboundRetryService $outboundRetryService)
    {
        $this->outboundRetryService = $outboundRetryService;
    }

    /**
     * Recover stale locked outbound messages.
     *
     * @param int $limit
     * @return int
     */
    public function recoverStaleLockedMessages(int $limit = 100): int
    {
        $staleCutoff = now()->subSeconds((int) config('services.gateway.outbound_stale_lock_seconds', 300));

        $ids = OutboundMessage::query()
            ->where('status', 'sending')
            ->whereNotNull('locked_at')
            ->where('locked_at', '<=', $staleCutoff)
            ->orderBy('locked_at')
            ->limit($limit)
            ->pluck('id');

        $recoveredCount = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$recoveredCount, $staleCutoff) {
                $message = OutboundMessage::query()->lockForUpdate()->find($id);

                if ($message === null) {
                    return;
                }

                if ($message->status !== 'sending' || $message->locked_at === null || $message->locked_at->greaterThan($staleCutoff)) {
                    return;
                }

                $willRetry = $this->outboundRetryService->canRetry($message);

                $this->outboundRetryService->handleSendFailure(
                    $message,
                    'Recovered stale locked message (sending timeout)',
                    'stale_lock_recovery'
                );

                $recoveredCount++;

                Log::warning('Stale lock recovered', [
                    'message_id' => $message->id,
                    'company_id' => $message->company_id,
                    'sim_id' => $message->sim_id,
                    'locked_at' => optional($message->locked_at)->toDateTimeString(),
                ]);

                if (!$willRetry) {
                    Log::error('Stale lock marked final failed', [
                        'message_id' => $message->id,
                        'company_id' => $message->company_id,
                        'sim_id' => $message->sim_id,
                    ]);
                }
            });
        }

        return $recoveredCount;
    }
}
