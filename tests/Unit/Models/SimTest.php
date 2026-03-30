<?php

namespace Tests\Unit\Models;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function operator_status_helpers_return_expected_booleans(): void
    {
        $company = $this->createCompany();

        $active = $this->createSim($company, ['operator_status' => 'active']);
        $paused = $this->createSim($company, ['operator_status' => 'paused']);
        $blocked = $this->createSim($company, ['operator_status' => 'blocked']);

        $this->assertTrue($active->isOperatorActive());
        $this->assertTrue($paused->isOperatorPaused());
        $this->assertTrue($blocked->isOperatorBlocked());
    }

    /** @test */
    public function accepts_new_assignments_uses_both_flags(): void
    {
        $company = $this->createCompany();

        $enabled = $this->createSim($company, [
            'accept_new_assignments' => true,
            'disabled_for_new_assignments' => false,
        ]);

        $disabledByOperator = $this->createSim($company, [
            'accept_new_assignments' => false,
            'disabled_for_new_assignments' => false,
        ]);

        $disabledByHealth = $this->createSim($company, [
            'accept_new_assignments' => true,
            'disabled_for_new_assignments' => true,
        ]);

        $this->assertTrue($enabled->acceptsNewAssignments());
        $this->assertFalse($disabledByOperator->acceptsNewAssignments());
        $this->assertFalse($disabledByHealth->acceptsNewAssignments());
    }

    /** @test */
    public function mark_successful_sets_last_success_at_to_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 10:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['last_success_at' => null]);

        $sim->markSuccessful();

        $this->assertNotNull($sim->fresh()->last_success_at);
        $this->assertSame('2026-03-30 10:00:00', $sim->fresh()->last_success_at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function minutes_since_last_success_returns_null_or_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $neverSuccessful = $this->createSim($company, ['last_success_at' => null]);
        $recent = $this->createSim($company, ['last_success_at' => Carbon::now()->subMinutes(17)]);

        $this->assertNull($neverSuccessful->minutesSinceLastSuccess());
        $this->assertSame(17, $recent->minutesSinceLastSuccess());
    }
}
