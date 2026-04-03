<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\OutboundMessage;
use App\Models\Sim;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NormalizePausedQueuedToPendingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:normalize-paused-queued-to-pending
                            {company_id : Company ID for explicit tenant boundary}
                            {sim_id : SIM ID to normalize}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'One-time Phase 2 normalization: legacy paused queued rows -> pending';

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
            return self::FAILURE;
        }

        if ($simId <= 0) {
            $this->error('Invalid sim_id. Expected a positive integer.');
            return self::FAILURE;
        }

        $company = Company::query()->find($companyId);

        if ($company === null) {
            $this->error('Company not found: '.$companyId);
            return self::FAILURE;
        }

        $sim = Sim::query()->find($simId);

        if ($sim === null) {
            $this->error('SIM not found: '.$simId);
            return self::FAILURE;
        }

        if ((int) $sim->company_id !== (int) $company->id) {
            $this->error('SIM does not belong to provided company.');

            Log::warning('Paused-queued normalization rejected: company mismatch', [
                'company_id' => $companyId,
                'sim_id' => $simId,
                'sim_company_id' => $sim->company_id,
            ]);

            return self::FAILURE;
        }

        if (!$sim->isOperatorPaused()) {
            $this->error('Normalization is restricted to paused SIMs only for safety.');
            $this->line('Current SIM operator_status: '.(string) $sim->operator_status);

            return self::FAILURE;
        }

        $query = OutboundMessage::query()
            ->where('company_id', $companyId)
            ->where('sim_id', $simId)
            ->where('status', 'queued')
            ->whereNull('queued_at')
            ->whereNull('sent_at')
            ->whereNull('failed_at')
            ->whereNull('locked_at');

        $candidateCount = (int) $query->count();

        if ($candidateCount === 0) {
            $this->line('No legacy queued rows found for normalization.');
            $this->line('Company ID: '.$companyId);
            $this->line('SIM ID: '.$simId);
            $this->line('Updated rows: 0');

            return self::SUCCESS;
        }

        $updatedCount = (int) $query->update([
            'status' => 'pending',
        ]);

        $this->line('Paused queued normalization completed.');
        $this->line('Company ID: '.$companyId);
        $this->line('SIM ID: '.$simId);
        $this->line('Updated rows: '.$updatedCount);

        Log::info('Paused queued rows normalized to pending', [
            'company_id' => $companyId,
            'sim_id' => $simId,
            'candidate_count' => $candidateCount,
            'updated_count' => $updatedCount,
        ]);

        return self::SUCCESS;
    }
}
