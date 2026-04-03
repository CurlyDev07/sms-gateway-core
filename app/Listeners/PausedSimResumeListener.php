<?php

namespace App\Listeners;

use App\Events\SimOperatorStatusChanged;
use App\Services\QueueRebuildService;
use Illuminate\Support\Facades\Log;
use Throwable;

class PausedSimResumeListener
{
    /**
     * Handle the event.
     *
     * @param \App\Events\SimOperatorStatusChanged $event
     * @return void
     */
    public function handle(SimOperatorStatusChanged $event): void
    {
        if ($event->oldStatus !== 'paused' || $event->newStatus !== 'active') {
            return;
        }

        try {
            app(QueueRebuildService::class)->rebuildSimQueue(
                (int) $event->companyId,
                (int) $event->simId
            );

            Log::info('Paused->active auto-requeue rebuild completed', [
                'company_id' => $event->companyId,
                'sim_id' => $event->simId,
            ]);
        } catch (Throwable $e) {
            Log::error('Paused->active auto-requeue rebuild failed', [
                'company_id' => $event->companyId,
                'sim_id' => $event->simId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
