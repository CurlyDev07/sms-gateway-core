<?php

namespace App\Console\Commands;

use App\Services\InboundRelayRetryService;
use Illuminate\Console\Command;

class RetryInboundRelaysCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gateway:retry-inbound-relays {--limit=100}';

    /**
     * @var string
     */
    protected $description = 'Dispatch due inbound relay retries';

    /**
     * @param \App\Services\InboundRelayRetryService $inboundRelayRetryService
     * @return int
     */
    public function handle(InboundRelayRetryService $inboundRelayRetryService)
    {
        $limit = (int) $this->option('limit');
        $count = $inboundRelayRetryService->dispatchDueRetries($limit);

        $this->info('Dispatched inbound relay retries: '.$count);

        return 0;
    }
}
