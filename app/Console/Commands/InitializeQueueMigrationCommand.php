<?php

namespace App\Console\Commands;

use App\Models\Sim;
use App\Services\QueueRebuildService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class InitializeQueueMigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:init-queue-migration
                            {--all : Run for all companies/SIMs}
                            {--company-id= : Restrict to one company}
                            {--sim-id= : Restrict to one SIM (requires --company-id)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'One-time Phase 2 queue seeding from DB pending truth into Redis per-SIM queues';

    /**
     * Execute the console command.
     *
     * @param \App\Services\QueueRebuildService $queueRebuildService
     * @return int
     */
    public function handle(QueueRebuildService $queueRebuildService): int
    {
        $all = (bool) $this->option('all');
        $companyIdOption = $this->option('company-id');
        $simIdOption = $this->option('sim-id');

        $companyId = null;
        $simId = null;

        if ($companyIdOption !== null && $companyIdOption !== '') {
            if (!is_numeric($companyIdOption) || (int) $companyIdOption <= 0) {
                $this->error('Invalid --company-id value. Expected a positive integer.');
                return self::FAILURE;
            }

            $companyId = (int) $companyIdOption;
        }

        if ($simIdOption !== null && $simIdOption !== '') {
            if (!is_numeric($simIdOption) || (int) $simIdOption <= 0) {
                $this->error('Invalid --sim-id value. Expected a positive integer.');
                return self::FAILURE;
            }

            $simId = (int) $simIdOption;
        }

        if ($simId !== null && $companyId === null) {
            $this->error('--sim-id requires --company-id for explicit tenant boundary.');
            return self::FAILURE;
        }

        if ($all && ($companyId !== null || $simId !== null)) {
            $this->error('--all cannot be combined with --company-id or --sim-id.');
            return self::FAILURE;
        }

        if (!$all && $companyId === null && $simId === null) {
            $this->error('Refusing unscoped run. Use --all or provide --company-id (optionally with --sim-id).');
            return self::FAILURE;
        }

        $query = Sim::query()->orderBy('id');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if ($simId !== null) {
            $query->where('id', $simId);
        }

        $targetSims = $query->get(['id', 'company_id']);

        if ($targetSims->isEmpty()) {
            $this->line('No SIMs matched the requested scope.');
            return self::SUCCESS;
        }

        $targetCount = $targetSims->count();
        $processed = 0;
        $failed = 0;
        $pendingTotal = 0;
        $enqueuedTotal = 0;

        Log::info('Queue migration initialization started', [
            'scope_all' => $all,
            'company_id' => $companyId,
            'sim_id' => $simId,
            'target_sims' => $targetCount,
        ]);

        foreach ($targetSims as $targetSim) {
            try {
                $result = $queueRebuildService->rebuildSimQueue(
                    (int) $targetSim->company_id,
                    (int) $targetSim->id
                );

                $processed++;
                $pendingTotal += (int) $result['pending_count'];
                $enqueuedTotal += (int) $result['enqueued_count'];

                $this->line(
                    'Rebuilt SIM '.$targetSim->id.
                    ' (company '.$targetSim->company_id.')'.
                    ' pending='.$result['pending_count'].
                    ', enqueued='.$result['enqueued_count']
                );
            } catch (Throwable $e) {
                $failed++;

                $this->error(
                    'Failed SIM '.$targetSim->id.
                    ' (company '.$targetSim->company_id.'): '.$e->getMessage()
                );

                Log::error('Queue migration initialization failed for SIM', [
                    'company_id' => $targetSim->company_id,
                    'sim_id' => $targetSim->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Queue migration initialization completed', [
            'scope_all' => $all,
            'company_id' => $companyId,
            'sim_id' => $simId,
            'target_sims' => $targetCount,
            'processed' => $processed,
            'failed' => $failed,
            'pending_total' => $pendingTotal,
            'enqueued_total' => $enqueuedTotal,
        ]);

        $this->line('Queue migration initialization completed.');
        $this->line('Scope: '.($all ? 'all' : ($simId !== null ? "company {$companyId}, sim {$simId}" : "company {$companyId}")));
        $this->line('Target SIMs: '.$targetCount);
        $this->line('Processed: '.$processed);
        $this->line('Failed: '.$failed);
        $this->line('Total pending rows found: '.$pendingTotal);
        $this->line('Total rows enqueued to Redis: '.$enqueuedTotal);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
