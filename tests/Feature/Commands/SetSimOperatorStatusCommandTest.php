<?php

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SetSimOperatorStatusCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_updates_sim_operator_status_when_company_boundary_matches(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $this->artisan('gateway:set-sim-operator-status', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'paused',
        ])->expectsOutput('SIM operator status updated')
            ->assertExitCode(0);

        $this->assertSame('paused', $sim->fresh()->operator_status);
    }

    /** @test */
    public function it_returns_no_change_message_when_status_is_already_set(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $this->artisan('gateway:set-sim-operator-status', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'status' => 'active',
        ])->expectsOutput('No status change needed')
            ->assertExitCode(0);

        $this->assertSame('active', $sim->fresh()->operator_status);
    }

    /** @test */
    public function it_rejects_company_mismatch(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA']);
        $companyB = $this->createCompany(['code' => 'CMPB']);
        $sim = $this->createSim($companyA, ['operator_status' => 'active']);

        $this->artisan('gateway:set-sim-operator-status', [
            'company_id' => $companyB->id,
            'sim_id' => $sim->id,
            'status' => 'paused',
        ])->expectsOutput('SIM does not belong to authenticated company')
            ->assertExitCode(1);

        $this->assertSame('active', $sim->fresh()->operator_status);
    }
}
