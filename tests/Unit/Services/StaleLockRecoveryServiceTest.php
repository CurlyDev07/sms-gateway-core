<?php

namespace Tests\Unit\Services;

use App\Models\OutboundMessage;
use App\Services\StaleLockRecoveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class StaleLockRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private StaleLockRecoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StaleLockRecoveryService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_recovers_only_stale_sending_locked_rows_using_db_state_and_keeps_same_sim(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 10:00:00'));
        config()->set('services.gateway.outbound_stale_lock_seconds', 300);

        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);

        $recoverable = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $simA->id,
            'customer_phone' => '09173000100',
            'status' => 'sending',
            'retry_count' => 2,
            'locked_at' => now()->subMinutes(10),
        ]);

        $freshSending = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $simA->id,
            'customer_phone' => '09173000101',
            'status' => 'sending',
            'retry_count' => 1,
            'locked_at' => now()->subMinutes(2),
        ]);

        $pendingStale = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $simA->id,
            'customer_phone' => '09173000102',
            'status' => 'pending',
            'retry_count' => 3,
            'locked_at' => now()->subMinutes(20),
        ]);

        $sendingWithoutLock = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $simB->id,
            'customer_phone' => '09173000103',
            'status' => 'sending',
            'retry_count' => 5,
            'locked_at' => null,
        ]);

        $count = $this->service->recoverStaleLockedMessages(100);

        $this->assertSame(1, $count);

        $recoveredFresh = $recoverable->fresh();
        $this->assertSame('pending', $recoveredFresh->status);
        $this->assertSame(3, (int) $recoveredFresh->retry_count);
        $this->assertSame($simA->id, (int) $recoveredFresh->sim_id);
        $this->assertNull($recoveredFresh->locked_at);
        $this->assertSame(
            'Recovered stale locked message (sending timeout)',
            $recoveredFresh->failure_reason
        );
        $this->assertNotNull($recoveredFresh->scheduled_at);
        $this->assertSame('2026-04-03 10:05:00', $recoveredFresh->scheduled_at->format('Y-m-d H:i:s'));

        $this->assertSame('sending', $freshSending->fresh()->status);
        $this->assertSame('pending', $pendingStale->fresh()->status);
        $this->assertSame('sending', $sendingWithoutLock->fresh()->status);
        $this->assertSame($simB->id, (int) $sendingWithoutLock->fresh()->sim_id);
    }

    /** @test */
    public function it_respects_recovery_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 11:00:00'));
        config()->set('services.gateway.outbound_stale_lock_seconds', 300);

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $first = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09173000110',
            'status' => 'sending',
            'retry_count' => 0,
            'locked_at' => now()->subMinutes(12),
        ]);

        $second = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09173000111',
            'status' => 'sending',
            'retry_count' => 0,
            'locked_at' => now()->subMinutes(11),
        ]);

        $count = $this->service->recoverStaleLockedMessages(1);

        $this->assertSame(1, $count);
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('sending', $second->fresh()->status);
    }

    private function createOutboundMessage(array $attributes): OutboundMessage
    {
        return OutboundMessage::query()->create(array_merge([
            'message' => 'stale-recovery-test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'pending',
            'retry_count' => 0,
        ], $attributes));
    }
}
