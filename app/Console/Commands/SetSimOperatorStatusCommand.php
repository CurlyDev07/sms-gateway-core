<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Sim;
use App\Services\SimOperatorStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SetSimOperatorStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:set-sim-operator-status
                            {company_id : Company ID for explicit tenant boundary}
                            {sim_id : SIM ID to update}
                            {status : Operator status (active|paused|blocked)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set SIM operator status with explicit company ownership enforcement';

    /**
     * Execute the console command.
     *
     * @param \App\Services\SimOperatorStatusService $simOperatorStatusService
     * @return int
     */
    public function handle(SimOperatorStatusService $simOperatorStatusService)
    {
        $companyId = (int) $this->argument('company_id');
        $simId = (int) $this->argument('sim_id');
        $status = (string) $this->argument('status');

        if ($companyId <= 0) {
            $this->error('Invalid company_id. Expected a positive integer.');
            return 1;
        }

        if ($simId <= 0) {
            $this->error('Invalid sim_id. Expected a positive integer.');
            return 1;
        }

        if (!$simOperatorStatusService->validateStatus($status)) {
            $this->error('Invalid status. Allowed values: active, paused, blocked.');
            return 1;
        }

        $company = Company::query()->find($companyId);

        if ($company === null) {
            $this->error('Company not found: '.$companyId);
            return 1;
        }

        $sim = Sim::query()->find($simId);

        if ($sim === null) {
            $this->error('SIM not found: '.$simId);
            return 1;
        }

        try {
            $oldStatus = (string) $sim->operator_status;
            $simOperatorStatusService->setOperatorStatus($sim, $status, $company);
            $newStatus = (string) $sim->fresh()->operator_status;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            Log::warning('Set SIM operator status failed', [
                'company_id' => $companyId,
                'sim_id' => $simId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return 1;
        }

        if ($oldStatus === $newStatus) {
            $this->line('No status change needed');
            $this->line('Company ID: '.$companyId);
            $this->line('SIM ID: '.$simId);
            $this->line('Current status remains: '.$newStatus);

            return 0;
        }

        $this->line('SIM operator status updated');
        $this->line('Company ID: '.$companyId);
        $this->line('SIM ID: '.$simId);
        $this->line('Old status: '.$oldStatus);
        $this->line('New status: '.$newStatus);

        Log::info('Set SIM operator status command completed', [
            'company_id' => $companyId,
            'sim_id' => $simId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        return 0;
    }
}
