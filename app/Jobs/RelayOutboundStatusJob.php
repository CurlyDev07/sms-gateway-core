<?php

namespace App\Jobs;

use App\Models\OutboundMessage;
use App\Services\OutboundStatusRelayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RelayOutboundStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    public $tries = 6;

    /**
     * @var array<int,int>
     */
    public $backoff = [10, 30, 60, 120, 300];

    /**
     * @var int
     */
    protected $outboundMessageId;

    /**
     * @var string
     */
    protected $expectedStatus;

    /**
     * @var string
     */
    protected $eventId;

    /**
     * @var string|null
     */
    protected $fromStatus;

    /**
     * @param int $outboundMessageId
     * @param string $expectedStatus
     * @param string $eventId
     * @param string|null $fromStatus
     * @return void
     */
    public function __construct(int $outboundMessageId, string $expectedStatus, string $eventId, ?string $fromStatus = null)
    {
        $this->afterCommit = true;
        $this->outboundMessageId = $outboundMessageId;
        $this->expectedStatus = strtolower(trim($expectedStatus));
        $this->eventId = $eventId;
        $this->fromStatus = $fromStatus !== null ? strtolower(trim($fromStatus)) : null;
    }

    /**
     * @param \App\Services\OutboundStatusRelayService $relayService
     * @return void
     */
    public function handle(OutboundStatusRelayService $relayService): void
    {
        $message = OutboundMessage::query()
            ->with(['company.chatAppIntegration', 'sim'])
            ->find($this->outboundMessageId);

        if ($message === null) {
            return;
        }

        $currentStatus = strtolower((string) $message->status);

        if ($currentStatus !== $this->expectedStatus) {
            Log::info('Outbound status callback skipped: status changed before callback send', [
                'outbound_message_id' => $message->id,
                'expected_status' => $this->expectedStatus,
                'current_status' => $currentStatus,
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $ok = $relayService->relay($message, $this->eventId, $this->fromStatus);

        if (!$ok) {
            throw new RuntimeException('outbound_status_callback_failed');
        }
    }
}
