<?php

namespace App\Console\Commands;

use App\Models\Sim;
use App\Services\SimFailoverService;
use Illuminate\Console\Command;

class FailoverSimCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gateway:failover-sim {simId}';

    /**
     * @var string
     */
    protected $description = 'Run failover for one unavailable SIM';

    /**
     * @param \App\Services\SimFailoverService $simFailoverService
     * @return int
     */
    public function handle(SimFailoverService $simFailoverService)
    {
        $simId = (int) $this->argument('simId');

        $sim = Sim::query()->find($simId);

        if ($sim === null) {
            $this->error('SIM not found: '.$simId);
            return 1;
        }

        $result = $simFailoverService->failoverSim($sim);

        $this->line('Failed SIM: '.$result['failed_sim_id']);
        $this->line('Replacement SIM: '.($result['replacement_sim_id'] ?? 'none'));
        $this->line('Messages moved: '.$result['messages_moved']);
        $this->line('Assignments moved: '.$result['assignments_moved']);
        $this->line('Deferred: '.($result['deferred'] ? 'yes' : 'no'));

        if (!empty($result['reason'])) {
            $this->line('Reason: '.$result['reason']);
        }

        return 0;
    }
}
