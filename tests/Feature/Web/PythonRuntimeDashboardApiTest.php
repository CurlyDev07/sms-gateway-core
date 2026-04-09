<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class PythonRuntimeDashboardApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    public function test_runtime_api_requires_dashboard_tenant_binding(): void
    {
        $user = User::factory()->create([
            'company_id' => null,
        ]);

        $this->actingAs($user)
            ->getJson('/dashboard/api/runtime/python')
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'dashboard_tenant_not_bound');
    }

    public function test_runtime_api_returns_health_and_tenant_filtered_discovery_rows(): void
    {
        $company = $this->createCompany();
        $this->createSim($company, ['imsi' => '515031234567890']);

        $otherCompany = $this->createCompany();
        $this->createSim($otherCompany, ['imsi' => '515039999999999']);

        $user = User::factory()->create([
            'company_id' => $company->id,
        ]);

        Http::fake([
            'http://python-engine.test/health' => Http::response([
                'status' => 'ok',
            ], 200),
            'http://python-engine.test/modems/discover' => Http::response([
                'ok' => true,
                'modems' => [
                    [
                        'device_id' => 'modem-a',
                        'sim_id' => '515031234567890',
                        'port' => '/dev/ttyUSB0',
                        'at_ok' => true,
                    ],
                    [
                        'device_id' => 'modem-b',
                        'sim_id' => '515039999999999',
                        'port' => '/dev/ttyUSB1',
                        'at_ok' => true,
                    ],
                ],
            ], 200),
        ]);

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_health_path', '/health');
        config()->set('sms.python_api_discover_path', '/modems/discover');

        $this->actingAs($user)
            ->getJson('/dashboard/api/runtime/python')
            ->assertOk()
            ->assertJsonPath('health.ok', true)
            ->assertJsonPath('discovery.ok', true)
            ->assertJsonPath('discovery.discovered_total', 2)
            ->assertJsonPath('discovery.tenant_visible_total', 1)
            ->assertJsonCount(1, 'discovery.modems')
            ->assertJsonPath('discovery.modems.0.device_id', 'modem-a')
            ->assertJsonPath('discovery.modems.0.sim_id', '515031234567890');
    }

    public function test_runtime_api_returns_structured_failure_when_python_is_unreachable(): void
    {
        $company = $this->createCompany();
        $user = User::factory()->create([
            'company_id' => $company->id,
        ]);

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_health_path', '/health');
        config()->set('sms.python_api_discover_path', '/modems/discover');

        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $this->actingAs($user)
            ->getJson('/dashboard/api/runtime/python')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('health.ok', false)
            ->assertJsonPath('health.error', 'connection_failed')
            ->assertJsonPath('discovery.ok', false)
            ->assertJsonPath('discovery.error', 'connection_failed')
            ->assertJsonPath('discovery.tenant_visible_total', 0);
    }
}
