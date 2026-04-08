<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentsDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignments_dashboard_page_renders_read_only_shell(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard/assignments');

        $response->assertOk()
            ->assertSee('Assignments Status')
            ->assertSee('GET /api/assignments')
            ->assertSee('Migration')
            ->assertSee('X-API-KEY')
            ->assertSee('X-API-SECRET')
            ->assertSee('customer_phone (optional)')
            ->assertSee('sim_id (optional)')
            ->assertSee('Safe To Migrate');
    }
}
