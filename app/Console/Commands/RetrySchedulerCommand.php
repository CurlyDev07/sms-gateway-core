<?php

namespace App\Console\Commands;

use App\Models\OutboundMessage;
use App\Services\RedisQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetrySchedulerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:retry-scheduler {--limit=100 : Max due retry rows to process per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enqueue due retry rows (pending + scheduled_at <= now) into Redis per-SIM queues';

    /**
     * Execute the console command.
     *
     * @param \App\Services\RedisQueueService $redisQueueService
     * @return int
     */
    public function handle(RedisQueueService $redisQueueService): int
    {
        $limit = (int) $this->option('limit');

        if ($limit <= 0) {
            $this->error('Invalid --limit value. Expected a positive integer.');
            return self::FAILURE;
        }

        $dueIds = OutboundMessage::query()
            ->where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereHas('sim', function ($query) {
                $query->where('operator_status', '!=', 'paused');
            })
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $eligible = 0;
        $enqueued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($dueIds as $id) {
            $claimed = $this->claimDueRetryMessage((int) $id);

            if ($claimed === null) {
                $skipped++;
                continue;
            }

            if ($claimed['sim_id'] === null) {
                $this->restorePending((int) $claimed['message_id']);

                Log::warning('Retry scheduler skipped row with null sim_id', [
                    'message_id' => $claimed['message_id'],
                    'company_id' => $claimed['company_id'],
                ]);

                $skipped++;
                continue;
            }

            $eligible++;

            try {
                $redisQueueService->enqueue(
                    (int) $claimed['sim_id'],
                    (int) $claimed['message_id'],
                    (string) $claimed['message_type']
                );

                $enqueued++;

                Log::info('Retry message enqueued', [
                    'message_id' => (int) $claimed['message_id'],
                    'company_id' => (int) $claimed['company_id'],
                    'sim_id' => (int) $claimed['sim_id'],
                    'retry_count' => (int) $claimed['retry_count'],
                    'scheduled_at' => (string) $claimed['scheduled_at'],
                ]);
            } catch (Throwable $e) {
                $this->restorePending((int) $claimed['message_id']);

                $failed++;

                Log::error('Retry scheduler enqueue failed; row restored to pending', [
                    'message_id' => (int) $claimed['message_id'],
                    'company_id' => (int) $claimed['company_id'],
                    'sim_id' => (int) $claimed['sim_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('Retry scheduler run completed.');
        $this->line('Limit: '.$limit);
        $this->line('Due rows scanned: '.$dueIds->count());
        $this->line('Eligible rows claimed: '.$eligible);
        $this->line('Enqueued: '.$enqueued);
        $this->line('Skipped: '.$skipped);
        $this->line('Enqueue failures: '.$failed);

        Log::info('Retry scheduler run completed', [
            'limit' => $limit,
            'due_rows_scanned' => $dueIds->count(),
            'eligible_rows_claimed' => $eligible,
            'enqueued' => $enqueued,
            'skipped' => $skipped,
            'enqueue_failures' => $failed,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Claim one due retry row by transitioning pending -> queued inside a short transaction.
     *
     * @param int $messageId
     * @return array<string, int|string|null>|null
     */
    protected function claimDueRetryMessage(int $messageId): ?array
    {
        return DB::transaction(function () use ($messageId) {
            /** @var \App\Models\OutboundMessage|null $message */
            $message = OutboundMessage::query()
                ->where('id', $messageId)
                ->where('status', 'pending')
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', now())
                ->whereHas('sim', function ($query) {
                    $query->where('operator_status', '!=', 'paused');
                })
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return null;
            }

            $queuedAt = now();
            $scheduledAt = $message->scheduled_at ? $message->scheduled_at->toDateTimeString() : null;

            $message->update([
                'status' => 'queued',
                'queued_at' => $queuedAt,
                'scheduled_at' => null,
            ]);

            return [
                'message_id' => (int) $message->id,
                'company_id' => (int) $message->company_id,
                'sim_id' => $message->sim_id !== null ? (int) $message->sim_id : null,
                'message_type' => (string) $message->message_type,
                'retry_count' => (int) $message->retry_count,
                'scheduled_at' => $scheduledAt,
            ];
        });
    }

    /**
     * Restore row back to pending state when enqueue fails.
     *
     * @param int $messageId
     * @return void
     */
    protected function restorePending(int $messageId): void
    {
        OutboundMessage::query()
            ->where('id', $messageId)
            ->where('status', 'queued')
            ->update([
                'status' => 'pending',
                'queued_at' => null,
                'scheduled_at' => now(),
            ]);
    }
}
