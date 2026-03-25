<?php

namespace App\Console\Commands;

use App\Services\StaleLockRecoveryService;
use Illuminate\Console\Command;

class RecoverOutboundCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gateway:recover-outbound {--limit=100}';

    /**
     * @var string
     */
    protected $description = 'Recover stale locked outbound messages and apply retry/final-fail policy';

    /**
     * @param \App\Services\StaleLockRecoveryService $recoveryService
     * @return int
     */
    public function handle(StaleLockRecoveryService $recoveryService)
    {
        $limit = (int) $this->option('limit');
        $count = $recoveryService->recoverStaleLockedMessages($limit);

        $this->info('Recovered stale outbound messages: '.$count);

        return 0;
    }
}
