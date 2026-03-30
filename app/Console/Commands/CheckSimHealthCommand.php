<?php

namespace App\Console\Commands;

use App\Models\Sim;
use App\Services\SimHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSimHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:check-sim-health {--company-id= : Optional company ID filter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check SIM health using last_success_at and apply assignment-disable guardrail';

    /**
     * Execute the console command.
     *
     * @param \App\Services\SimHealthService $simHealthService
     * @return int
     */
    public function handle(SimHealthService $simHealthService)
    {
        $companyIdOption = $this->option('company-id');
        $companyId = null;

        if ($companyIdOption !== null && $companyIdOption !== '') {
            if (!is_numeric($companyIdOption) || (int) $companyIdOption <= 0) {
                $this->error('Invalid --company-id value. Expected a positive integer.');
                return 1;
            }

            $companyId = (int) $companyIdOption;
        }

        $query = Sim::query()->orderBy('id');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $checked = 0;
        $healthy = 0;
        $unhealthy = 0;
        $disableFlagChanged = 0;

        Log::info('SIM health check started', [
            'company_id_filter' => $companyId,
        ]);

        $query->chunkById(200, function ($sims) use (
            $simHealthService,
            &$checked,
            &$healthy,
            &$unhealthy,
            &$disableFlagChanged
        ) {
            foreach ($sims as $sim) {
                $result = $simHealthService->checkHealth($sim);

                $checked++;

                if (($result['status'] ?? null) === 'healthy') {
                    $healthy++;
                } else {
                    $unhealthy++;
                }

                if (!empty($result['disable_flag_changed'])) {
                    $disableFlagChanged++;
                }

                if (($result['status'] ?? null) === 'unhealthy' || !empty($result['disable_flag_changed'])) {
                    Log::warning('SIM health check result', [
                        'sim_id' => $sim->id,
                        'company_id' => $sim->company_id,
                        'status' => $result['status'] ?? null,
                        'reason' => $result['reason'] ?? null,
                        'minutes_since_last_success' => $result['minutes_since_last_success'] ?? null,
                        'disabled_for_new_assignments' => $result['disabled_for_new_assignments'] ?? null,
                        'disable_flag_changed' => $result['disable_flag_changed'] ?? null,
                    ]);
                }
            }
        });

        Log::info('SIM health check completed', [
            'company_id_filter' => $companyId,
            'checked' => $checked,
            'healthy' => $healthy,
            'unhealthy' => $unhealthy,
            'disable_flag_changed' => $disableFlagChanged,
        ]);

        $this->line('SIM health check completed');
        $this->line('Company filter: '.($companyId !== null ? $companyId : 'all'));
        $this->line('Checked: '.$checked);
        $this->line('Healthy: '.$healthy);
        $this->line('Unhealthy: '.$unhealthy);
        $this->line('Disable flag changed: '.$disableFlagChanged);

        return 0;
    }
}

