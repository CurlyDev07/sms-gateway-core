<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimFleetStatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sim_fleet_status_page_renders_read_only_shell(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard/sims');

        $response->assertOk()
            ->assertSee('SIM Fleet Status')
            ->assertSee('GET /api/sims')
            ->assertSee('Migration')
            ->assertSee('X-API-KEY')
            ->assertSee('X-API-SECRET')
            ->assertSee('Phone Number')
            ->assertSee('Queue Followup')
            ->assertSee('Disabled For New Assignments');
    }
}
