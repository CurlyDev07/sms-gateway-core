<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorsDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_operators_dashboard_page_renders_read_only_shell(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard/operators');

        $response->assertOk()
            ->assertSee('Operator Management')
            ->assertSee('GET /dashboard/api/operators')
            ->assertSee('Operator Role');
    }
}
