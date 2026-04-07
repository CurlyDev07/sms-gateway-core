<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class DashboardUxPolishTest extends TestCase
{
    public function test_dashboard_pages_show_consistent_cross_navigation_links(): void
    {
        $paths = [
            '/dashboard',
            '/dashboard/sims',
            '/dashboard/assignments',
            '/dashboard/migration',
            '/dashboard/messages/status',
            '/dashboard/sims/1',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);

            $response->assertOk()
                ->assertSee('Dashboard Home')
                ->assertSee('SIM Fleet')
                ->assertSee('Assignments')
                ->assertSee('Migration')
                ->assertSee('Message Status');
        }
    }

    public function test_credentialed_pages_include_clear_saved_credentials_action(): void
    {
        $paths = [
            '/dashboard/sims',
            '/dashboard/assignments',
            '/dashboard/migration',
            '/dashboard/messages/status',
            '/dashboard/sims/1',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);

            $response->assertOk()
                ->assertSee('Clear Saved Credentials');
        }
    }
}

