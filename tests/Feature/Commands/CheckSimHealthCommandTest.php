<?php

namespace Tests\Feature\Commands;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class CheckSimHealthCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_checks_health_and_updates_disable_flag_based_on_last_success_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $healthy = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(10),
            'disabled_for_new_assignments' => true,
        ]);
        $unhealthy = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(45),
            'disabled_for_new_assignments' => false,
        ]);

        $this->artisan('gateway:check-sim-health')
            ->expectsOutput('SIM health check completed')
            ->expectsOutput('Checked: 2')
            ->assertExitCode(0);

        $this->assertFalse($healthy->fresh()->disabled_for_new_assignments);
        $this->assertTrue($unhealthy->fresh()->disabled_for_new_assignments);
    }

    /** @test */
    public function it_can_filter_by_company_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $targetCompany = $this->createCompany(['code' => 'TARGET']);
        $otherCompany = $this->createCompany(['code' => 'OTHER']);

        $targetSimA = $this->createSim($targetCompany, ['last_success_at' => Carbon::now()->subMinutes(31)]);
        $targetSimB = $this->createSim($targetCompany, ['last_success_at' => Carbon::now()->subMinutes(5)]);
        $otherSim = $this->createSim($otherCompany, ['last_success_at' => Carbon::now()->subMinutes(31)]);

        $this->artisan('gateway:check-sim-health', ['--company-id' => $targetCompany->id])
            ->expectsOutput('SIM health check completed')
            ->expectsOutput('Company filter: '.$targetCompany->id)
            ->expectsOutput('Checked: 2')
            ->assertExitCode(0);

        $this->assertTrue($targetSimA->fresh()->disabled_for_new_assignments);
        $this->assertFalse($targetSimB->fresh()->disabled_for_new_assignments);
        $this->assertFalse($otherSim->fresh()->disabled_for_new_assignments);
    }
}
