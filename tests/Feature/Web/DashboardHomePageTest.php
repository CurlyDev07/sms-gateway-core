<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class DashboardHomePageTest extends TestCase
{
    public function test_dashboard_home_page_renders_navigation_links(): void
    {
        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertSee('Gateway Dashboard')
            ->assertSee('SIM Fleet')
            ->assertSee('Assignments')
            ->assertSee('Migration')
            ->assertSee('Message Status')
            ->assertSee('/dashboard/sims')
            ->assertSee('/dashboard/assignments')
            ->assertSee('/dashboard/migration')
            ->assertSee('/dashboard/messages/status');
    }
}
