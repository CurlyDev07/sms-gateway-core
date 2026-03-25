<?php

namespace App\Jobs;

use App\Models\InboundMessage;
use App\Services\InboundRelayRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RelayInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    protected $inboundMessageId;

    /**
     * Create a new job instance.
     *
     * @param \App\Models\InboundMessage $message
     * @return void
     */
    public function __construct(InboundMessage $message)
    {
        $this->inboundMessageId = (int) $message->id;
    }

    /**
     * Execute the job.
     *
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
