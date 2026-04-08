<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimDetailControlPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sim_detail_control_page_renders_shell_for_given_sim_id(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard/sims/123');

        $response->assertOk()
            ->assertSee('SIM Detail / Control')
            ->assertSee('SIM ID:')
            ->assertSee('123')
            ->assertSee('Migration')
            ->assertSee('GET /dashboard/api/sims')
            ->assertSee('Set Active')
            ->assertSee('Set Paused')
            ->assertSee('Set Blocked')
            ->assertSee('Enable Assignments')
            ->assertSee('Disable Assignments')
            ->assertSee('Rebuild Queue');
    }
}
