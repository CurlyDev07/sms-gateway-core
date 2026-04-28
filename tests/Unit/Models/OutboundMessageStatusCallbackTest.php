<?php

namespace Tests\Unit\Models;

use App\Jobs\RelayOutboundStatusJob;
use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class OutboundMessageStatusCallbackTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_dispatches_status_callback_job_when_status_changes_to_terminal_value(): void
    {
        config()->set('services.chat_app.delivery_status_url', 'http://chatapp.test/api/gateway/delivery-status');
        config()->set('services.chat_app.tenant_key', 'tenant-dev');
        config()->set('services.chat_app.inbound_secret', 'dev-secret');

        Queue::fake();

        $message = $this->createOutboundMessage(['status' => 'queued']);

        $message->update(['status' => 'sending']);
        Queue::assertNothingPushed();

        $message->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        Queue::assertPushed(RelayOutboundStatusJob::class, 1);
    }

    /** @test */
    public function it_does_not_dispatch_status_callback_job_without_relay_settings(): void
    {
        config()->set('services.chat_app.delivery_status_url', null);
        config()->set('services.chat_app.tenant_key', null);
        config()->set('services.chat_app.inbound_secret', null);

        Queue::fake();

        $message = $this->createOutboundMessage(['status' => 'queued']);
        $message->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => 'SEND_FAILED',
        ]);

        Queue::assertNothingPushed();
    }

    protected function createOutboundMessage(array $overrides = []): OutboundMessage
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        return OutboundMessage::query()->create(array_merge([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09550090156',
            'message' => 'Message',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'pending',
            'retry_count' => 0,
        ], $overrides));
    }
}

