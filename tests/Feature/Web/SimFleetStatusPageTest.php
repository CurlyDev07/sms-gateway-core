<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class SimFleetStatusPageTest extends TestCase
{
    public function test_sim_fleet_status_page_renders_read_only_shell(): void
    {
        $response = $this->get('/dashboard/sims');

        $response->assertOk()
            ->assertSee('SIM Fleet Status')
            ->assertSee('GET /api/sims')
            ->assertSee('X-API-KEY')
            ->assertSee('X-API-SECRET')
            ->assertSee('Phone Number')
            ->assertSee('Queue Followup')
            ->assertSee('Disabled For New Assignments');
    }
}

