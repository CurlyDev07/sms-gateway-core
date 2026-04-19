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
    public function handle_send_failure_schedules_fixed_retry_in_seconds(): void
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
        $this->assertSame('2026-03-30 12:00:10', $fresh->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertNull($fresh->locked_at);
    }

    /** @test */
    public function handle_send_failure_uses_configured_retry_delay_seconds(): void
    {
        config()->set('services.gateway.outbound_retry_base_delay_seconds', 25);
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09170000004',
            'message' => 'Hello',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'failed',
            'retry_count' => 0,
            'locked_at' => now(),
        ]);

        $this->service->handleSendFailure($message, 'Provider timeout');

        $this->assertSame('2026-03-30 12:00:25', $message->fresh()->scheduled_at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function handle_permanent_failure_marks_message_as_failed_with_no_retry_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09170000003',
            'message' => 'Hello',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'sending',
            'retry_count' => 1,
            'locked_at' => now(),
        ]);

        $this->service->handlePermanentFailure($message, 'SEND_FAILED');

        $fresh = $message->fresh();

        $this->assertSame('failed', $fresh->status);
        $this->assertSame(2, (int) $fresh->retry_count);
        $this->assertSame('SEND_FAILED', $fresh->failure_reason);
        $this->assertNotNull($fresh->failed_at);
        $this->assertNull($fresh->scheduled_at);
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

    /** @test */
    public function classify_failure_marks_runtime_timeout_as_retryable(): void
    {
        $decision = $this->service->classifyFailure('RUNTIME_TIMEOUT', 'transport');

        $this->assertTrue($decision['retryable']);
        $this->assertSame('retryable', $decision['classification']);
    }

    /** @test */
    public function classify_failure_marks_invalid_response_as_non_retryable(): void
    {
        $decision = $this->service->classifyFailure('INVALID_RESPONSE', 'python_api');

        $this->assertFalse($decision['retryable']);
        $this->assertSame('non_retryable', $decision['classification']);
    }

    /** @test */
    public function classify_failure_marks_default_network_rejection_as_non_retryable(): void
    {
        $decision = $this->service->classifyFailure('SEND_FAILED', 'network');

        $this->assertFalse($decision['retryable']);
        $this->assertSame('non_retryable', $decision['classification']);
        $this->assertSame('carrier_rejection_network_layer', $decision['reason']);
    }

    /** @test */
    public function classify_failure_allows_retry_for_temporary_network_registration_issue(): void
    {
        $decision = $this->service->classifyFailure('NETWORK_NOT_REGISTERED', 'network');

        $this->assertTrue($decision['retryable']);
        $this->assertSame('retryable', $decision['classification']);
    }
}
