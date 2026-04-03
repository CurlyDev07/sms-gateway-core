<?php

namespace App\Console\Commands;

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
    protected $description = 'DISABLED: automatic failover command (manual migration only)';

    /**
     * @return int
     */
    public function handle()
    {
        $this->error('Automatic failover is disabled. Manual migration only.');
        $this->line('Use the Phase 1 manual migration workflow instead of gateway:failover-sim.');

        return self::FAILURE;
    }
}
