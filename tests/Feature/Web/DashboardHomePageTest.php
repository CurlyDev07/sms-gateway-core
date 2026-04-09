<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardHomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_home_page_renders_navigation_links(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertSee('Gateway Dashboard')
            ->assertSee('SIM Fleet')
            ->assertSee('Assignments')
            ->assertSee('Migration')
            ->assertSee('Message Status')
            ->assertSee('Python Runtime')
            ->assertSee('My Account')
            ->assertSee('Operators')
            ->assertSee('Audit Log')
            ->assertSee('Change Password')
            ->assertSee('/dashboard/sims')
            ->assertSee('/dashboard/assignments')
            ->assertSee('/dashboard/migration')
            ->assertSee('/dashboard/messages/status')
            ->assertSee('/dashboard/runtime/python')
            ->assertSee('/dashboard/account')
            ->assertSee('/dashboard/operators')
            ->assertSee('/dashboard/audit')
            ->assertSee('/dashboard/password');
    }
}
