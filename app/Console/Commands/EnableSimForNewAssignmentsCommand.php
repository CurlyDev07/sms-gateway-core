<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Sim;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnableSimForNewAssignmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:enable-sim-new-assignments
                            {company_id : Company ID for explicit tenant boundary}
                            {sim_id : SIM ID to enable for new assignments}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable a SIM for new customer assignments (accept_new_assignments=true)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $companyId = (int) $this->argument('company_id');
        $simId = (int) $this->argument('sim_id');

        if ($companyId <= 0) {
            $this->error('Invalid company_id. Expected a positive integer.');
            return 1;
        }

        if ($simId <= 0) {
            $this->error('Invalid sim_id. Expected a positive integer.');
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

        if ((int) $sim->company_id !== (int) $company->id) {
            $this->error('SIM does not belong to provided company.');

            Log::warning('Enable SIM new assignments failed: company mismatch', [
                'company_id' => $companyId,
                'sim_id' => $simId,
                'sim_company_id' => $sim->company_id,
            ]);

            return 1;
        }

        $wasEnabled = (bool) $sim->accept_new_assignments;

        if ($wasEnabled) {
            $this->line('No change needed: SIM is already enabled for new assignments.');
            $this->line('Company ID: '.$companyId);
            $this->line('SIM ID: '.$simId);
            $this->line('accept_new_assignments: true');

            return 0;
        }

        $sim->update([
            'accept_new_assignments' => true,
        ]);

        $this->line('SIM enabled for new assignments.');
        $this->line('Company ID: '.$companyId);
        $this->line('SIM ID: '.$simId);
        $this->line('accept_new_assignments: true');

        Log::info('SIM enabled for new assignments', [
            'company_id' => $companyId,
            'sim_id' => $simId,
            'accept_new_assignments' => true,
        ]);

        return 0;
    }
}

