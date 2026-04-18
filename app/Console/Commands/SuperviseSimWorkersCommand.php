<?php

namespace App\Console\Commands;

use App\Models\Sim;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class SuperviseSimWorkersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gateway:supervise-sim-workers
                            {--company-id= : Optional company ID filter}
                            {--poll=5 : Reconcile interval in seconds}
                            {--max-runtime=0 : Optional max runtime in seconds (0 = forever)}
                            {--once : Run a single reconcile pass and exit}
                            {--dry-run : Show reconciliation result without starting/stopping worker processes}';

    /**
     * @var string
     */
    protected $description = 'Auto-manage per-SIM worker processes so active mapped SIM IDs always have running workers';

    /**
     * @param array<int,Process> $workers
     * @return void
     */
    protected function stopAllWorkers(array &$workers): void
    {
        foreach ($workers as $simId => $process) {
            $this->stopWorkerProcess((int) $simId, $process);
            unset($workers[$simId]);
        }
    }

    /**
     * @return int
     */
    public function handle(): int
    {
        $companyId = $this->parseOptionalCompanyId();
        if ($companyId === false) {
            return self::FAILURE;
        }

        $pollSeconds = $this->parsePositiveIntOption('poll');
        if ($pollSeconds === null) {
            return self::FAILURE;
        }

        $maxRuntimeSeconds = $this->parseNonNegativeIntOption('max-runtime');
        if ($maxRuntimeSeconds === null) {
            return self::FAILURE;
        }

        $runOnce = (bool) $this->option('once');
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = microtime(true);

        /** @var array<int,Process> $workers */
        $workers = [];

        Log::info('SIM worker supervisor started', [
            'company_id_filter' => $companyId,
            'poll_seconds' => $pollSeconds,
            'max_runtime_seconds' => $maxRuntimeSeconds,
            'run_once' => $runOnce,
            'dry_run' => $dryRun,
        ]);

        try {
            while (true) {
                $desiredSimIds = $this->desiredSimIds($companyId);
                $result = $this->reconcile($desiredSimIds, $workers, $dryRun);

                $this->line('SIM worker reconcile completed');
                $this->line('Desired SIM IDs: '.implode(',', $desiredSimIds));
                $this->line('Started: '.(int) $result['started']);
                $this->line('Stopped: '.(int) $result['stopped']);
                $this->line('Restarted dead: '.(int) $result['restarted_dead']);
                $this->line('Running workers: '.(int) $result['running_workers']);

                Log::info('SIM worker supervisor reconcile', [
                    'company_id_filter' => $companyId,
                    'dry_run' => $dryRun,
                    'desired_sim_ids' => $desiredSimIds,
                    'started' => $result['started'],
                    'stopped' => $result['stopped'],
                    'restarted_dead' => $result['restarted_dead'],
                    'running_workers' => $result['running_workers'],
                ]);

                if ($runOnce) {
                    break;
                }

                if ($maxRuntimeSeconds > 0 && (microtime(true) - $startedAt) >= $maxRuntimeSeconds) {
                    break;
                }

                sleep($pollSeconds);
            }
        } finally {
            if (!$dryRun) {
                $this->stopAllWorkers($workers);
            }
        }

        Log::info('SIM worker supervisor stopped', [
            'company_id_filter' => $companyId,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param array<int,int> $desiredSimIds
     * @param array<int,Process> $workers
     * @param bool $dryRun
     * @return array{started:int,stopped:int,restarted_dead:int,running_workers:int}
     */
    protected function reconcile(array $desiredSimIds, array &$workers, bool $dryRun): array
    {
        $desiredSet = array_fill_keys($desiredSimIds, true);
        $started = 0;
        $stopped = 0;
        $restartedDead = 0;

        foreach ($workers as $simId => $process) {
            if (!isset($desiredSet[$simId])) {
                if (!$dryRun) {
                    $this->stopWorkerProcess((int) $simId, $process);
                    unset($workers[$simId]);
                }
                $stopped++;
                continue;
            }

            if (!$process->isRunning()) {
                unset($workers[$simId]);
                if (!$dryRun) {
                    $workers[$simId] = $this->startWorkerProcess((int) $simId);
                }
                $restartedDead++;
            }
        }

        foreach ($desiredSimIds as $simId) {
            if (isset($workers[$simId])) {
                continue;
            }

            if (!$dryRun) {
                $workers[$simId] = $this->startWorkerProcess((int) $simId);
            }

            $started++;
        }

        return [
            'started' => $started,
            'stopped' => $stopped,
            'restarted_dead' => $restartedDead,
            'running_workers' => count($workers),
        ];
    }

    /**
     * @param int $simId
     * @return \Symfony\Component\Process\Process
     */
    protected function startWorkerProcess(int $simId): Process
    {
        $process = new Process(['php', 'artisan', 'gateway:process-sim', (string) $simId], base_path());
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();

        Log::info('SIM worker supervisor started process', [
            'sim_id' => $simId,
            'pid' => $process->getPid(),
        ]);

        return $process;
    }

    /**
     * @param int $simId
     * @param \Symfony\Component\Process\Process $process
     * @return void
     */
    protected function stopWorkerProcess(int $simId, Process $process): void
    {
        if ($process->isRunning()) {
            $process->stop(2);
        }

        Log::info('SIM worker supervisor stopped process', [
            'sim_id' => $simId,
        ]);
    }

    /**
     * @param int|null $companyId
     * @return array<int,int>
     */
    protected function desiredSimIds(?int $companyId): array
    {
        $query = Sim::query()
            ->where('status', 'active')
            ->where('operator_status', '!=', 'blocked')
            ->whereNotNull('imsi')
            ->orderBy('id');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->values()
            ->all();
    }

    /**
     * @return int|false|null
     */
    protected function parseOptionalCompanyId()
    {
        $option = $this->option('company-id');

        if ($option === null || $option === '') {
            return null;
        }

        if (!is_numeric($option) || (int) $option <= 0) {
            $this->error('Invalid --company-id value. Expected a positive integer.');
            return false;
        }

        return (int) $option;
    }

    /**
     * @param string $name
     * @return int|null
     */
    protected function parsePositiveIntOption(string $name): ?int
    {
        $option = $this->option($name);

        if (!is_numeric($option) || (int) $option <= 0) {
            $this->error('Invalid --'.$name.' value. Expected a positive integer.');
            return null;
        }

        return (int) $option;
    }

    /**
     * @param string $name
     * @return int|null
     */
    protected function parseNonNegativeIntOption(string $name): ?int
    {
        $option = $this->option($name);

        if (!is_numeric($option) || (int) $option < 0) {
            $this->error('Invalid --'.$name.' value. Expected a non-negative integer.');
            return null;
        }

        return (int) $option;
    }
}

