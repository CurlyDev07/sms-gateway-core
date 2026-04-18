<?php

namespace App\Console\Commands;

use App\Services\RuntimeSimSyncService;
use Illuminate\Console\Command;

class SyncRuntimeReadinessCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gateway:sync-runtime-readiness {--company-id= : Optional company ID filter}';

    /**
     * @var string
     */
    protected $description = 'Sync SIM assignment readiness flags using Python runtime IMSI readiness';

    /**
     * @param \App\Services\RuntimeSimSyncService $syncService
     * @return int
     */
    public function handle(RuntimeSimSyncService $syncService): int
    {
        $companyIdOption = $this->option('company-id');
        $companyId = null;

        if ($companyIdOption !== null && $companyIdOption !== '') {
            if (!is_numeric($companyIdOption) || (int) $companyIdOption <= 0) {
                $this->error('Invalid --company-id value. Expected a positive integer.');
                return self::FAILURE;
            }

            $companyId = (int) $companyIdOption;
        }

        $result = $syncService->sync($companyId);

        $this->line('Runtime readiness sync completed.');
        $this->line('Company filter: '.($companyId !== null ? (string) $companyId : 'all'));
        $this->line('Runtime modems total: '.(int) ($result['runtime_modems_total'] ?? 0));
        $this->line('Runtime IMSI total: '.(int) ($result['runtime_imsi_total'] ?? 0));
        $this->line('Runtime ready IMSI total: '.(int) ($result['runtime_ready_imsi_total'] ?? 0));
        $this->line('SIMs scanned: '.(int) ($result['sims_scanned'] ?? 0));
        $this->line('SIMs enabled: '.(int) ($result['sims_enabled'] ?? 0));
        $this->line('SIMs disabled: '.(int) ($result['sims_disabled'] ?? 0));
        $this->line('Guardrail skipped: '.(int) ($result['guardrail_skipped'] ?? 0));
        $this->line('Ineligible skipped: '.(int) ($result['ineligible_skipped'] ?? 0));

        if (($result['ok'] ?? false) !== true) {
            $this->error('Sync failed: '.(string) ($result['error'] ?? 'runtime_discovery_failed'));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

