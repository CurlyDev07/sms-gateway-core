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
            ->assertSee('Last Refresh Attempt')
            ->assertSee('Last Successful Load')
            ->assertSee('Runtime State')
            ->assertSee('Click Check Python Runtime to load current health/discovery state.')
            ->assertSee('error_layer=network')
            ->assertSee('check SIM load/balance')
            ->assertSee('Discovered Total')
            ->assertSee('Tenant Visible')
            ->assertSee('Fleet Snapshot')
            ->assertSee('Total Discovered')
            ->assertSee('Mapped')
            ->assertSee('Unmapped')
            ->assertSee('Send-Ready')
            ->assertSee('Fallback IDs')
            ->assertSee('Mapping Review')
            ->assertSee('Review-only visibility for runtime-to-Laravel reconciliation signals')
            ->assertSee('Needs Review')
            ->assertSee('Unmapped Rows')
            ->assertSee('Fallback Rows')
            ->assertSee('Unmapped + Send Ready')
            ->assertSee('Runtime SIM ID (IMSI/device)')
            ->assertSee('Tenant SIM DB ID')
            ->assertSee('How to read this page')
            ->assertSee('runtime/discovery identity for diagnostics and copy actions')
            ->assertSee('used by send-test and Laravel-side actions')
            ->assertSee('runtime row is linked to a tenant SIM record in Laravel')
            ->assertSee('runtime used a weaker device-based identity')
            ->assertSee('runtime currently reports the row as send-capable')
            ->assertSee('Mapping Status')
            ->assertSee('Identifier Source')
            ->assertSee('Runtime Send-Ready')
            ->assertSee('Probe Error')
            ->assertSee('Row Safety')
            ->assertSee('Strongest usable')
            ->assertSee('Usable with caution')
            ->assertSee('Visible only / not mapped')
            ->assertSee('Degraded')
            ->assertSee('Quick Filters')
            ->assertSee('All')
            ->assertSee('Needs Review')
            ->assertSee('Send Ready')
            ->assertSee('Unmapped + Send Ready')
            ->assertSee('Fallback')
            ->assertSee('Action Safety Legend')
            ->assertSee('View Details')
            ->assertSee('copies the runtime')
            ->assertSee('uses Laravel Tenant SIM DB ID')
            ->assertSee('Selected Row / Action Target')
            ->assertSee('Runtime context and Laravel action target are tracked separately')
            ->assertSee('Clear Selection')
            ->assertSee('Selected Runtime SIM ID')
            ->assertSee('Selected Tenant SIM DB ID')
            ->assertSee('Diagnostics Target')
            ->assertSee('Send-Test Target')
            ->assertSee('Last Action Context')
            ->assertSee('Row Diagnostics')
            ->assertSee('Select a row and click')
            ->assertSee('View Details')
            ->assertSee('Safety Classification')
            ->assertSee('Send-Test Actionability')
            ->assertSee('Mapping Review Status')
            ->assertSee('Needs Review Reason')
            ->assertSee('Review Flags')
            ->assertSee('Reconciliation Note')
            ->assertSee('Review only: no Laravel mapping action is performed on this page.')
            ->assertSee('Raw Row JSON')
            ->assertSee('No row selected yet.')
            ->assertSee('Send test will use Tenant SIM DB ID')
            ->assertSee('Copy SIM ID')
            ->assertSee('Use in Send Test')
            ->assertSee('Probe did not complete; send test disabled.')
            ->assertSee('No tenant SIM mapping for this runtime SIM ID; send test disabled.')
            ->assertSee('sim_id')
            ->assertSee('fallback device identifier')
            ->assertSee('Safety guide')
            ->assertSee('Python runtime is reachable, but modem discovery failed. Check discovery endpoint/runtime logs.');
    }
}
