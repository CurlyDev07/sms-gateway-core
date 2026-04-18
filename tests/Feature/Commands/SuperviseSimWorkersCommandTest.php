<?php

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SuperviseSimWorkersCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_lists_desired_sim_worker_ids_in_dry_run_mode(): void
    {
        $company = $this->createCompany(['code' => 'SUP-A']);

        $active = $this->createSim($company, [
            'imsi' => '515020241752004',
            'status' => 'active',
            'operator_status' => 'active',
        ]);
        $paused = $this->createSim($company, [
            'imsi' => '515020241752005',
            'status' => 'active',
            'operator_status' => 'paused',
        ]);
        $blocked = $this->createSim($company, [
            'imsi' => '515039219149367',
            'status' => 'active',
            'operator_status' => 'blocked',
        ]);
        $noImsi = $this->createSim($company, [
            'imsi' => null,
            'status' => 'active',
            'operator_status' => 'active',
        ]);

        $this->artisan('gateway:supervise-sim-workers', [
            '--once' => true,
            '--dry-run' => true,
            '--poll' => 1,
            '--company-id' => $company->id,
        ])
            ->expectsOutput('SIM worker reconcile completed')
            ->expectsOutput('Desired SIM IDs: '.$active->id.','.$paused->id)
            ->expectsOutput('Started: 2')
            ->expectsOutput('Stopped: 0')
            ->expectsOutput('Restarted dead: 0')
            ->expectsOutput('Running workers: 0')
            ->assertExitCode(0);

        $this->assertNotNull($blocked->fresh());
        $this->assertNotNull($noImsi->fresh());
    }

    /** @test */
    public function it_rejects_invalid_poll_option(): void
    {
        $this->artisan('gateway:supervise-sim-workers', [
            '--once' => true,
            '--dry-run' => true,
            '--poll' => 0,
        ])
            ->expectsOutput('Invalid --poll value. Expected a positive integer.')
            ->assertExitCode(1);
    }
}

