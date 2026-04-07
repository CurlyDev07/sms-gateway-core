<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class MessageStatusDashboardPageTest extends TestCase
{
    public function test_message_status_dashboard_page_renders_lookup_shell(): void
    {
        $response = $this->get('/dashboard/messages/status');

        $response->assertOk()
            ->assertSee('Message Status Lookup')
            ->assertSee('GET /api/messages/status')
            ->assertSee('X-API-KEY')
            ->assertSee('X-API-SECRET')
            ->assertSee('client_message_id (required)')
            ->assertSee('sim_id (optional)')
            ->assertSee('Lookup Status')
            ->assertSee('Failure Reason')
            ->assertSee('Retry Count');
    }
}
