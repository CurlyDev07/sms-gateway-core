<?php

namespace App\Jobs;

use App\Models\InboundMessage;
use App\Services\InboundRelayRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryInboundRelayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    protected $inboundMessageId;

    /**
     * @param int $inboundMessageId
     * @return void
     */
    public function __construct(int $inboundMessageId)
    {
        $this->inboundMessageId = $inboundMessageId;
    }

    /**
     * @param \App\Services\InboundRelayRetryService $inboundRelayRetryService
     * @return void
     */
    public function handle(InboundRelayRetryService $inboundRelayRetryService)
    {
        $message = InboundMessage::query()->find($this->inboundMessageId);

        if ($message === null) {
            return;
        }

        $inboundRelayRetryService->process($message);
    }
}
