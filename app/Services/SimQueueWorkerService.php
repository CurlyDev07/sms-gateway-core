<?php

namespace App\Services;

use App\Contracts\SmsSenderInterface;
use App\Models\OutboundMessage;
use App\Models\Sim;
use App\Models\SimDailyStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimQueueWorkerService
{
    /**
     * @var \App\Contracts\SmsSenderInterface
     */
    protected $smsSender;
    /**
     * @var \App\Services\SimStateService
     */
    protected $simStateService;
    /**
     * @var \App\Services\OutboundRetryService
     */
    protected $outboundRetryService;
    /**
     * @var \App\Services\RedisQueueService
     */
    protected $redisQueueService;
    /**
     * @var \App\Services\QueueRebuildService
     */
    protected $queueRebuildService;

    /**
     * @param \App\Contracts\SmsSenderInterface $smsSender
     */
    public function __construct(
        SmsSenderInterface $smsSender,
        SimStateService $simStateService,
        OutboundRetryService $outboundRetryService,
        RedisQueueService $redisQueueService,
        QueueRebuildService $queueRebuildService
    )
    {
        $this->smsSender = $smsSender;
        $this->simStateService = $simStateService;
        $this->outboundRetryService = $outboundRetryService;
        $this->redisQueueService = $redisQueueService;
        $this->queueRebuildService = $queueRebuildService;
    }

    /**
     * Run processing loop for a specific SIM.
     *
     * @param int $simId
     * @return void
     */
    public function run(int $simId): void
    {
        Log::info('SIM worker started', ['sim_id' => $simId]);

        while (true) {
            $sim = Sim::query()->find($simId);

            if ($sim === null) {
                Log::warning('SIM worker skipping: SIM not found', ['sim_id' => $simId]);
                $this->sleepSeconds($this->simStateService->getInactiveSleepSeconds());
                continue;
            }

            if (!$sim->isActive()) {
                $this->sleepSeconds($this->simStateService->getInactiveSleepSeconds());
                continue;
            }

            if ($sim->isOperatorPaused()) {
                $this->sleepSeconds($this->simStateService->getInactiveSleepSeconds());
                continue;
            }

            if (!$this->simStateService->canSend($sim)) {
                if ($sim->isCoolingDown()) {
                    $this->sleepSeconds($this->simStateService->getCooldownSleepSeconds());
                } else {
                    $this->sleepSeconds($this->simStateService->getDailyLimitSleepSeconds());
                }
                continue;
            }

            if ($this->queueRebuildService->hasLock((int) $sim->id)) {
                $this->sleepSeconds($this->simStateService->getIdleSleepSeconds());
                continue;
            }

            $messageId = $this->redisQueueService->popNext((int) $sim->id);

            if ($messageId === null) {
                $this->sleepSeconds($this->simStateService->getIdleSleepSeconds());
                continue;
            }

            $message = $this->claimPoppedMessage($sim, (int) $messageId);

            if ($message === null) {
                continue;
            }

            Log::info('SIM worker claimed message', [
                'sim_id' => $sim->id,
                'message_id' => $message->id,
                'company_id' => $message->company_id,
                'message_type' => $message->message_type,
            ]);

            $result = $this->smsSender->send(
                (int) $sim->id,
                (string) $message->customer_phone,
                (string) $message->message,
                [
                    'message_id' => $message->id,
                    'sim_id' => $sim->id,
                    'company_id' => app()->bound('tenant.company_id')
                        ? (int) app('tenant.company_id')
                        : (int) $message->company_id,
                    'message_type' => $message->message_type,
                ]
            );
            $isSuccess = (bool) $result->success;

            if ($isSuccess) {
                $this->markMessageSent($sim, $message);

                Log::info('SIM worker send success', [
                    'sim_id' => $sim->id,
                    'message_id' => $message->id,
                ]);
            } else {
                $this->markMessageFailed($message, $result->error);

                Log::warning('SIM worker send failure', [
                    'sim_id' => $sim->id,
                    'message_id' => $message->id,
                    'error' => $result->error,
                ]);
            }

            $this->sleepSeconds($this->simStateService->getSleepSecondsForMessageType($sim, $message->message_type));
        }
    }

    /**
     * Claim one popped message ID by re-checking DB truth before send.
     *
     * @param \App\Models\Sim $sim
     * @param int $messageId
     * @return \App\Models\OutboundMessage|null
     */
    protected function claimPoppedMessage(Sim $sim, int $messageId): ?OutboundMessage
    {
        return DB::transaction(function () use ($sim, $messageId) {
            $message = OutboundMessage::query()
                ->where('id', $messageId)
                ->where('company_id', $sim->company_id)
                ->where('sim_id', $sim->id)
                ->where('status', 'queued')
                ->where(function ($q) {
                    $q->whereNull('scheduled_at')
                        ->orWhere('scheduled_at', '<=', now());
                })
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                Log::warning('SIM worker dropped stale redis message ID', [
                    'sim_id' => $sim->id,
                    'message_id' => $messageId,
                ]);

                return null;
            }

            $message->status = 'sending';
            $message->locked_at = now();
            $message->save();

            return $message->fresh();
        });
    }

    /**
     * Mark message sent and update SIM counters/stats.
     *
     * @param \App\Models\Sim $sim
     * @param \App\Models\OutboundMessage $message
     * @return void
     */
    protected function markMessageSent(Sim $sim, OutboundMessage $message): void
    {
        DB::transaction(function () use ($sim, $message) {
            $message = OutboundMessage::query()->lockForUpdate()->find($message->id);
            $sim = Sim::query()->lockForUpdate()->find($sim->id);

            if ($message === null || $sim === null) {
                return;
            }

            $message->update([
                'status' => 'sent',
                'sent_at' => now(),
                'scheduled_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'locked_at' => null,
            ]);

            $this->simStateService->markSendSuccess($sim, $message->message_type);

            $stats = SimDailyStat::query()->firstOrCreate(
                [
                    'sim_id' => $sim->id,
                    'stat_date' => today()->toDateString(),
                ],
                [
                    'sent_count' => 0,
                    'sent_chat_count' => 0,
                    'sent_auto_reply_count' => 0,
                    'sent_follow_up_count' => 0,
                    'sent_blast_count' => 0,
                    'failed_count' => 0,
                    'inbound_count' => 0,
                ]
            );

            $stats->increment('sent_count');

            $typeColumn = $this->sentTypeColumn($message->message_type);

            if ($typeColumn !== null) {
                $stats->increment($typeColumn);
            }
        });
    }

    /**
     * Mark message failed without retry workflow.
     *
     * @param \App\Models\OutboundMessage $message
     * @param string|null $error
     * @return void
     */
    protected function markMessageFailed(OutboundMessage $message, ?string $error = null): void
    {
        DB::transaction(function () use ($message, $error) {
            $lockedMessage = OutboundMessage::query()->lockForUpdate()->find($message->id);

            if ($lockedMessage === null) {
                return;
            }

            $this->outboundRetryService->handleSendFailure($lockedMessage, $error, 'worker_send_failure');
        });
    }


    /**
     * Get stats column name for message type sent count.
     *
     * @param string $messageType
     * @return string|null
     */
    protected function sentTypeColumn(string $messageType): ?string
    {
        if ($messageType === 'CHAT') {
            return 'sent_chat_count';
        }

        if ($messageType === 'AUTO_REPLY') {
            return 'sent_auto_reply_count';
        }

        if ($messageType === 'FOLLOW_UP') {
            return 'sent_follow_up_count';
        }

        if ($messageType === 'BLAST') {
            return 'sent_blast_count';
        }

        return null;
    }

    /**
     * Sleep helper.
     *
     * @param int $seconds
     * @return void
     */
    protected function sleepSeconds(int $seconds): void
    {
        sleep(max(1, $seconds));
    }
}
