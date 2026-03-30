<?php

namespace Tests\Unit\Services;

use App\Services\SimHealthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimHealthServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private SimHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SimHealthService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function unhealthy_sim_with_multiple_company_sims_is_auto_disabled_for_new_assignments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $target = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(31),
            'disabled_for_new_assignments' => false,
        ]);
        $this->createSim($company);

        $result = $this->service->checkHealth($target);

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame('no_success_within_30_minutes', $result['reason']);
        $this->assertTrue($target->fresh()->disabled_for_new_assignments);
        $this->assertTrue($result['disable_flag_changed']);
    }

    /** @test */
    public function unhealthy_single_sim_company_is_not_auto_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $target = $this->createSim($company, [
            'last_success_at' => null,
            'disabled_for_new_assignments' => false,
        ]);

        $result = $this->service->checkHealth($target);

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame('no_success_recorded', $result['reason']);
        $this->assertFalse($target->fresh()->disabled_for_new_assignments);
        $this->assertFalse($result['disable_flag_changed']);
    }

    /** @test */
    public function recovered_sim_is_auto_re_enabled_for_new_assignments_when_company_has_multiple_sims(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $target = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(5),
            'disabled_for_new_assignments' => true,
        ]);
        $this->createSim($company);

        $result = $this->service->checkHealth($target);

        $this->assertSame('healthy', $result['status']);
        $this->assertNull($result['reason']);
        $this->assertFalse($target->fresh()->disabled_for_new_assignments);
        $this->assertTrue($result['disable_flag_changed']);
    }

    /** @test */
    public function compute_stuck_age_returns_expected_threshold_flags(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subHours(25),
        ]);

        $stuck = $this->service->computeStuckAge($sim);

        $this->assertTrue($stuck['stuck_6h']);
        $this->assertTrue($stuck['stuck_24h']);
        $this->assertFalse($stuck['stuck_3d']);
    }
}
