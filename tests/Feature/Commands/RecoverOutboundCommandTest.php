<?php

namespace Tests\Feature\Commands;

use App\Models\OutboundMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class RecoverOutboundCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_recovers_stale_sending_rows_and_reports_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 12:00:00'));
        config()->set('services.gateway.outbound_stale_lock_seconds', 300);

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $recoverable = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09174000100',
            'status' => 'sending',
            'retry_count' => 4,
            'locked_at' => now()->subMinutes(8),
        ]);

        $freshSending = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09174000101',
            'status' => 'sending',
            'retry_count' => 1,
            'locked_at' => now()->subMinutes(1),
        ]);

        $this->artisan('gateway:recover-outbound', [
            '--limit' => 100,
        ])->expectsOutput('Recovered stale outbound messages: 1')
            ->assertExitCode(0);

        $recoveredFresh = $recoverable->fresh();
        $this->assertSame('pending', $recoveredFresh->status);
        $this->assertSame(5, (int) $recoveredFresh->retry_count);
        $this->assertNull($recoveredFresh->locked_at);
        $this->assertSame($sim->id, (int) $recoveredFresh->sim_id);

        $this->assertSame('sending', $freshSending->fresh()->status);
        $this->assertSame($sim->id, (int) $freshSending->fresh()->sim_id);
    }

    private function createOutboundMessage(array $attributes): OutboundMessage
    {
        return OutboundMessage::query()->create(array_merge([
            'message' => 'recover-command-test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'pending',
            'retry_count' => 0,
        ], $attributes));
    }
}

