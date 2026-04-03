<?php

namespace Tests\Feature\Commands;

use Tests\TestCase;

class FailoverCommandsDisabledTest extends TestCase
{
    /** @test */
    public function failover_sim_command_returns_failure_and_disabled_message(): void
    {
        $this->artisan('gateway:failover-sim', [
            'simId' => 1,
        ])->expectsOutput('Automatic failover is disabled. Manual migration only.')
            ->expectsOutput('Use the Phase 1 manual migration workflow instead of gateway:failover-sim.')
            ->assertExitCode(1);
    }

    /** @test */
    public function scan_failover_command_returns_failure_and_disabled_message(): void
    {
        $this->artisan('gateway:scan-failover')
            ->expectsOutput('Automatic failover scan is disabled. Manual migration only.')
            ->expectsOutput('Use the Phase 1 manual migration workflow instead of gateway:scan-failover.')
            ->assertExitCode(1);
    }
}

