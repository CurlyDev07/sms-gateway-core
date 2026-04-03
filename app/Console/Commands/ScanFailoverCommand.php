<?php

namespace App\Console\Commands;

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
    protected $description = 'DISABLED: automatic failover scan command (manual migration only)';

    /**
     * @return int
     */
    public function handle()
    {
        $this->error('Automatic failover scan is disabled. Manual migration only.');
        $this->line('Use the Phase 1 manual migration workflow instead of gateway:scan-failover.');

        return self::FAILURE;
    }
}
