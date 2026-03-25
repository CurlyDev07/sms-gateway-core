<?php

namespace App\Console\Commands;

use App\Models\Sim;
use App\Services\SimFailoverService;
use Illuminate\Console\Command;

class ScanFailoverCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gateway:scan-failover {--limit=200}';

    /**
     * @var string
     */
    protected $description = 'Scan unavailable SIMs and attempt failover';

    /**
     * @param \App\Services\SimFailoverService $simFailoverService
     * @return int
     */
    public function handle(SimFailoverService $simFailoverService)
    {
        $limit = (int) $this->option('limit');

        $candidates = Sim::query()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        $deferred = 0;
        $messagesMoved = 0;
        $assignmentsMoved = 0;

        foreach ($candidates as $sim) {
            if (!$simFailoverService->isSimUnavailable($sim)) {
                continue;
            }

            $result = $simFailoverService->failoverSim($sim);
            $processed++;
            $messagesMoved += (int) $result['messages_moved'];
            $assignmentsMoved += (int) $result['assignments_moved'];

            if ($result['deferred']) {
                $deferred++;
            }
        }

        $this->line('Failover scan processed: '.$processed);
        $this->line('Deferred: '.$deferred);
        $this->line('Messages moved: '.$messagesMoved);
        $this->line('Assignments moved: '.$assignmentsMoved);

        return 0;
    }
}
