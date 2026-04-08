<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardUxPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_pages_show_consistent_cross_navigation_links(): void
    {
        $this->actingAs(User::factory()->create());

        $paths = [
            '/dashboard',
            '/dashboard/sims',
            '/dashboard/assignments',
            '/dashboard/migration',
            '/dashboard/messages/status',
            '/dashboard/operators',
            '/dashboard/sims/1',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);

            $response->assertOk()
                ->assertSee('Dashboard Home')
                ->assertSee('SIM Fleet')
                ->assertSee('Assignments')
                ->assertSee('Migration')
                ->assertSee('Message Status')
                ->assertSee('Operators');
        }
    }

    public function test_dashboard_pages_do_not_prompt_for_raw_api_credentials(): void
    {
        $this->actingAs(User::factory()->create());

        $paths = [
            '/dashboard/sims',
            '/dashboard/assignments',
            '/dashboard/migration',
            '/dashboard/messages/status',
            '/dashboard/operators',
            '/dashboard/sims/1',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);

            $response->assertOk()
                ->assertDontSee('X-API-KEY')
                ->assertDontSee('X-API-SECRET')
                ->assertDontSee('Clear Saved Credentials');
        }
    }
}
