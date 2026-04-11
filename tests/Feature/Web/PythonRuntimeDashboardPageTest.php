<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PythonRuntimeDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_python_runtime_dashboard_page_renders_runtime_shell(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard/runtime/python');

        $response->assertOk()
            ->assertSee('Python Runtime')
            ->assertSee('GET /dashboard/api/runtime/python')
            ->assertSee('POST /dashboard/api/runtime/python/send-test')
            ->assertSee('Check Python Runtime')
            ->assertSee('Send Runtime Test SMS')
            ->assertSee('error_layer=network')
            ->assertSee('check SIM load/balance')
            ->assertSee('Discovered Total')
            ->assertSee('Tenant Visible')
            ->assertSee('Runtime SIM ID (IMSI/device)')
            ->assertSee('Tenant SIM DB ID')
            ->assertSee('Probe Error')
            ->assertSee('Copy SIM ID')
            ->assertSee('Use in Send Test')
            ->assertSee('Probe did not complete; send test disabled.')
            ->assertSee('No tenant SIM mapping for this runtime SIM ID; send test disabled.')
            ->assertSee('sim_id')
            ->assertSee('fallback device identifier')
            ->assertSee('Python runtime is reachable, but modem discovery failed. Check discovery endpoint/runtime logs.');
    }
}
