<?php

namespace Tests\Unit\Services;

use App\Services\SimStateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimStateServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected SimStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SimStateService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function mark_send_success_sets_last_success_at_for_normal_message_type(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05 10:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'status' => 'active',
            'mode' => 'NORMAL',
            'last_success_at' => null,
        ]);

        $this->service->markSendSuccess($sim, 'BLAST');

        $fresh = $sim->fresh();
        $this->assertNotNull($fresh->last_success_at);
        $this->assertSame('2026-04-05 10:00:00', $fresh->last_success_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-05 10:00:00', $fresh->last_sent_at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function mark_send_success_sets_last_success_at_for_burst_message_type(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05 11:00:00'));

        $company = $this->createCompany();
        // burst_count below burst_limit so no cooldown is triggered
        $sim = $this->createSim($company, [
            'status' => 'active',
            'mode' => 'BURST',
            'burst_count' => 0,
            'burst_limit' => 30,
            'last_success_at' => null,
        ]);

        $this->service->markSendSuccess($sim, 'CHAT');

        $fresh = $sim->fresh();
        $this->assertNotNull($fresh->last_success_at);
        $this->assertSame('2026-04-05 11:00:00', $fresh->last_success_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-05 11:00:00', $fresh->last_sent_at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function mark_send_success_sets_last_success_at_when_burst_triggers_cooldown(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05 12:00:00'));

        $company = $this->createCompany();
        // burst_count at burst_limit - 1 so this send tips into cooldown
        $sim = $this->createSim($company, [
            'status' => 'active',
            'mode' => 'BURST',
            'burst_count' => 29,
            'burst_limit' => 30,
            'cooldown_min_seconds' => 60,
            'cooldown_max_seconds' => 60,
            'last_success_at' => null,
        ]);

        $this->service->markSendSuccess($sim, 'CHAT');

        $fresh = $sim->fresh();
        // last_success_at must be set even when the burst tips into cooldown
        $this->assertNotNull($fresh->last_success_at);
        $this->assertSame('2026-04-05 12:00:00', $fresh->last_success_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-05 12:00:00', $fresh->last_sent_at->format('Y-m-d H:i:s'));
        // confirm cooldown state was also applied correctly
        $this->assertSame('COOLDOWN', $fresh->mode);
        $this->assertNotNull($fresh->cooldown_until);
    }
}
