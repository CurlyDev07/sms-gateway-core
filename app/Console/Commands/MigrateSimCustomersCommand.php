<?php

namespace App\Console\Commands;

use App\Services\SimMigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class MigrateSimCustomersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:migrate-sim-customers
                            {company_id : Company ID for explicit tenant boundary}
                            {from_sim_id : Source SIM ID}
                            {to_sim_id : Destination SIM ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually bulk-migrate sticky assignments and eligible outbound rows from one SIM to another (DB-first)';

    /**
     * Execute the console command.
     *
     * @param \App\Services\SimMigrationService $simMigrationService
     * @return int
     */
    public function handle(SimMigrationService $simMigrationService): int
    {
        $companyId = (int) $this->argument('company_id');
        $fromSimId = (int) $this->argument('from_sim_id');
        $toSimId = (int) $this->argument('to_sim_id');

        if ($companyId <= 0) {
            $this->error('Invalid company_id. Expected a positive integer.');
            return 1;
        }

        if ($fromSimId <= 0) {
            $this->error('Invalid from_sim_id. Expected a positive integer.');
            return 1;
        }

        if ($toSimId <= 0) {
            $this->error('Invalid to_sim_id. Expected a positive integer.');
            return 1;
        }

        try {
            $result = $simMigrationService->migrateBulk(
                $companyId,
                $fromSimId,
                $toSimId
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            Log::warning('Bulk SIM migration rejected', [
                'company_id' => $companyId,
                'from_sim_id' => $fromSimId,
                'to_sim_id' => $toSimId,
                'error' => $e->getMessage(),
            ]);

            return 1;
        } catch (Throwable $e) {
            $this->error('Bulk migration failed due to an unexpected error.');

            Log::error('Bulk SIM migration failed unexpectedly', [
                'company_id' => $companyId,
                'from_sim_id' => $fromSimId,
                'to_sim_id' => $toSimId,
                'error' => $e->getMessage(),
            ]);

            return 1;
        }

        $this->line('Bulk SIM migration completed.');
        $this->line('Company ID: '.(int) $result['company_id']);
        $this->line('From SIM ID: '.(int) $result['from_sim_id']);
        $this->line('To SIM ID: '.(int) $result['to_sim_id']);
        $this->line('Assignments moved: '.(int) $result['assignments_moved']);
        $this->line('Messages moved: '.(int) $result['messages_moved']);

        Log::info('Bulk SIM migration command completed', [
            'company_id' => (int) $result['company_id'],
            'from_sim_id' => (int) $result['from_sim_id'],
            'to_sim_id' => (int) $result['to_sim_id'],
            'assignments_moved' => (int) $result['assignments_moved'],
            'messages_moved' => (int) $result['messages_moved'],
        ]);

        return 0;
    }
}

