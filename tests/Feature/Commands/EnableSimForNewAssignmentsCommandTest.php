<?php

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class EnableSimForNewAssignmentsCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_enables_accept_new_assignments_for_matching_company_sim(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['accept_new_assignments' => false]);

        $this->artisan('gateway:enable-sim-new-assignments', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
        ])->expectsOutput('SIM enabled for new assignments.')
            ->assertExitCode(0);

        $this->assertTrue($sim->fresh()->accept_new_assignments);
    }

    /** @test */
    public function it_handles_enable_noop_cleanly(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['accept_new_assignments' => true]);

        $this->artisan('gateway:enable-sim-new-assignments', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
        ])->expectsOutput('No change needed: SIM is already enabled for new assignments.')
            ->assertExitCode(0);

        $this->assertTrue($sim->fresh()->accept_new_assignments);
    }

    /** @test */
    public function it_rejects_cross_company_enable_attempt_and_keeps_state_unchanged(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA']);
        $companyB = $this->createCompany(['code' => 'CMPB']);
        $sim = $this->createSim($companyB, ['accept_new_assignments' => false]);

        $this->artisan('gateway:enable-sim-new-assignments', [
            'company_id' => $companyA->id,
            'sim_id' => $sim->id,
        ])->expectsOutput('SIM does not belong to provided company.')
            ->assertExitCode(1);

        $this->assertFalse($sim->fresh()->accept_new_assignments);
    }
}
