<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_sets_page_title_and_active_nav_for_sim_fleet_page(): void
    {
        $this->actingAs(User::factory()->create([
            'must_change_password' => false,
        ]));

        $response = $this->get('/dashboard/sims');

        $response->assertOk()
            ->assertSee('<title>SIM Fleet Status | Gateway Dashboard</title>', false)
            ->assertSee('href="/dashboard/sims" class="nav-link is-active" aria-current="page"', false)
            ->assertSee('Account:')
            ->assertSee('href="/dashboard/account"', false)
            ->assertSee('href="/dashboard/password"', false);
    }

    public function test_layout_marks_sim_fleet_nav_active_on_sim_detail_page(): void
    {
        $this->actingAs(User::factory()->create([
            'must_change_password' => false,
        ]));

        $response = $this->get('/dashboard/sims/42');

        $response->assertOk()
            ->assertSee('href="/dashboard/sims" class="nav-link is-active" aria-current="page"', false)
            ->assertSee('<title>SIM Detail / Control | Gateway Dashboard</title>', false);
    }

    public function test_forced_password_change_page_keeps_nav_hidden(): void
    {
        $this->actingAs(User::factory()->create([
            'must_change_password' => true,
        ]));

        $response = $this->get('/dashboard/password/change');

        $response->assertOk()
            ->assertSee('<title>Change Temporary Password | Gateway Dashboard</title>', false)
            ->assertDontSee('Dashboard Home')
            ->assertDontSee('Account:')
            ->assertDontSee('href="/dashboard/account"', false);
    }
}
