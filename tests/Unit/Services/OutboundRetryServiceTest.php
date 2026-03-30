<?php

namespace Tests\Unit\Services;

use App\Models\OutboundMessage;
use App\Services\OutboundRetryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class OutboundRetryServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private OutboundRetryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OutboundRetryService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function handle_send_failure_schedules_fixed_five_minute_retry_forever_policy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09170000001',
            'message' => 'Hello',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'failed',
            'retry_count' => 2,
            'locked_at' => now(),
        ]);

        $this->service->handleSendFailure($message, 'Provider timeout');

        $fresh = $message->fresh();

        $this->assertSame('pending', $fresh->status);
        $this->assertSame(3, (int) $fresh->retry_count);
        $this->assertSame('Provider timeout', $fresh->failure_reason);
        $this->assertSame('2026-03-30 12:05:00', $fresh->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertNull($fresh->locked_at);
    }

    /** @test */
    public function can_retry_is_always_true_under_phase_zero_forever_retry_policy(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09170000002',
            'message' => 'Retry',
            'message_type' => 'FOLLOW_UP',
            'priority' => 50,
            'status' => 'failed',
            'retry_count' => 999,
        ]);

        $this->assertTrue($this->service->canRetry($message));
    }
}
