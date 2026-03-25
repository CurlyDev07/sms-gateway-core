<?php

namespace App\Console\Commands;

use App\Services\SimQueueWorkerService;
use Illuminate\Console\Command;

class ProcessSimCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:process-sim {simId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process outbound queue for a single SIM worker loop';

    /**
     * Execute the console command.
     *
     * @param \App\Services\SimQueueWorkerService $workerService
     * @return int
     */
    public function handle(SimQueueWorkerService $workerService)
    {
        $simId = (int) $this->argument('simId');

        $workerService->run($simId);

        return 0;
    }
}
