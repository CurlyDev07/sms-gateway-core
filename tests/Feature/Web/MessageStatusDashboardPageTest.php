<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageStatusDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_status_dashboard_page_renders_lookup_shell(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard/messages/status');

        $response->assertOk()
            ->assertSee('Message Status Lookup')
            ->assertSee('GET /dashboard/api/messages/status')
            ->assertSee('client_message_id (required)')
            ->assertSee('sim_id (optional)')
            ->assertSee('Lookup Status')
            ->assertSee('Failure Reason')
            ->assertSee('Retry Count');
    }
}
