<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_dashboard_page_renders_tooling_shell(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard/migration');

        $response->assertOk()
            ->assertSee('Migration Tools')
            ->assertSee('GET /api/assignments')
            ->assertSee('Mark Safe')
            ->assertSee('Set Assignment')
            ->assertSee('Migrate Single Customer')
            ->assertSee('Bulk Migrate')
            ->assertSee('customer_phone (migrate single)')
            ->assertSee('from_sim_id (bulk)')
            ->assertSee('to_sim_id (bulk)');
    }
}
