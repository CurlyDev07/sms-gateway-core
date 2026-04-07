<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class SimDetailControlPageTest extends TestCase
{
    public function test_sim_detail_control_page_renders_shell_for_given_sim_id(): void
    {
        $response = $this->get('/dashboard/sims/123');

        $response->assertOk()
            ->assertSee('SIM Detail / Control')
            ->assertSee('SIM ID:')
            ->assertSee('123')
            ->assertSee('Migration')
            ->assertSee('GET /api/sims')
            ->assertSee('Set Active')
            ->assertSee('Set Paused')
            ->assertSee('Set Blocked')
            ->assertSee('Enable Assignments')
            ->assertSee('Disable Assignments')
            ->assertSee('Rebuild Queue');
    }
}
