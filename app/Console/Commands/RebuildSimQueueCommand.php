<?php

namespace App\Console\Commands;

use App\Services\QueueRebuildService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RebuildSimQueueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:rebuild-sim-queue
                            {company_id : Company ID for explicit tenant boundary}
                            {sim_id : SIM ID to rebuild}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild one SIM Redis queue set from DB pending truth (Phase 2 DB-first rebuild)';

    /**
     * Execute the console command.
     *
     * @param \App\Services\QueueRebuildService $queueRebuildService
     * @return int
     */
    public function handle(QueueRebuildService $queueRebuildService): int
    {
        $companyId = (int) $this->argument('company_id');
        $simId = (int) $this->argument('sim_id');

        if ($companyId <= 0) {
            $this->error('Invalid company_id. Expected a positive integer.');
            return self::FAILURE;
        }

        if ($simId <= 0) {
            $this->error('Invalid sim_id. Expected a positive integer.');
            return self::FAILURE;
        }

        try {
            $result = $queueRebuildService->rebuildSimQueue($companyId, $simId);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            Log::warning('SIM queue rebuild command rejected', [
                'company_id' => $companyId,
                'sim_id' => $simId,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('SIM queue rebuild failed due to an unexpected error.');

            Log::error('SIM queue rebuild command failed unexpectedly', [
                'company_id' => $companyId,
                'sim_id' => $simId,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->line('SIM queue rebuild completed.');
        $this->line('Company ID: '.(int) $result['company_id']);
        $this->line('SIM ID: '.(int) $result['sim_id']);
        $this->line('Pending rows found: '.(int) $result['pending_count']);
        $this->line('Rows enqueued to Redis: '.(int) $result['enqueued_count']);
        $this->line('Chat tier count: '.(int) $result['chat_count']);
        $this->line('Followup tier count: '.(int) $result['followup_count']);
        $this->line('Blasting tier count: '.(int) $result['blasting_count']);
        $this->line('Lock key: '.(string) $result['lock_key']);

        Log::info('SIM queue rebuild command completed', [
            'company_id' => (int) $result['company_id'],
            'sim_id' => (int) $result['sim_id'],
            'pending_count' => (int) $result['pending_count'],
            'enqueued_count' => (int) $result['enqueued_count'],
            'chat_count' => (int) $result['chat_count'],
            'followup_count' => (int) $result['followup_count'],
            'blasting_count' => (int) $result['blasting_count'],
            'lock_key' => (string) $result['lock_key'],
        ]);

        return self::SUCCESS;
    }
}
