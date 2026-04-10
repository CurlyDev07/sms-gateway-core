<?php

namespace Tests\Feature\Web;

use App\Models\OutboundMessage;
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
                        'probe_error' => null,
                    ],
                    [
                        'device_id' => 'modem-b',
                        'sim_id' => '515039999999999',
                        'port' => '/dev/ttyUSB1',
                        'at_ok' => true,
                        'probe_error' => 'PROBE_TIMEOUT after 12.0s',
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
            ->assertJsonCount(2, 'discovery.all_modems')
            ->assertJsonPath('discovery.modems.0.device_id', 'modem-a')
            ->assertJsonPath('discovery.modems.0.sim_id', '515031234567890')
            ->assertJsonPath('discovery.modems.0.probe_error', null)
            ->assertJsonPath('discovery.all_modems.1.device_id', 'modem-b')
            ->assertJsonPath('discovery.all_modems.1.probe_error', 'PROBE_TIMEOUT after 12.0s');
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

    public function test_runtime_send_test_success_persists_sent_outbound_row_with_runtime_metadata(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => '515031234567890',
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_send_path', '/send');

        Http::fake([
            'http://python-engine.test/send' => Http::response([
                'success' => true,
                'message_id' => 'py-live-1001',
                'raw' => [
                    'device_id' => 'modem-1',
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->postJson('/dashboard/api/runtime/python/send-test', [
                'sim_id' => $sim->id,
                'customer_phone' => '09171234567',
                'message' => 'runtime send test',
                'client_message_id' => 'runtime-send-1001',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.status', 'sent')
            ->assertJsonPath('result.provider_message_id', 'py-live-1001');

        $message = OutboundMessage::query()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame('sent', $message->status);
        $this->assertSame('runtime-send-1001', $message->client_message_id);
        $this->assertSame('py-live-1001', data_get($message->metadata, 'python_runtime.provider_message_id'));
        $this->assertTrue((bool) data_get($message->metadata, 'python_runtime.success'));
    }

    public function test_runtime_send_test_unreachable_failure_is_structured_and_persisted(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => '515031234567890',
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_send_path', '/send');

        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $this->actingAs($user)
            ->postJson('/dashboard/api/runtime/python/send-test', [
                'sim_id' => $sim->id,
                'customer_phone' => '09171234567',
                'message' => 'runtime send test',
            ])
            ->assertStatus(502)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'runtime_send_failed')
            ->assertJsonPath('result.status', 'failed')
            ->assertJsonPath('result.error', 'RUNTIME_UNREACHABLE')
            ->assertJsonPath('result.error_layer', 'transport');

        $message = OutboundMessage::query()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame('failed', $message->status);
        $this->assertSame('RUNTIME_UNREACHABLE', $message->failure_reason);
    }

    public function test_runtime_send_test_timeout_failure_is_structured_and_persisted(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => '515031234567890',
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_send_path', '/send');

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->actingAs($user)
            ->postJson('/dashboard/api/runtime/python/send-test', [
                'sim_id' => $sim->id,
                'customer_phone' => '09171234567',
                'message' => 'runtime send test',
            ])
            ->assertStatus(502)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('result.status', 'failed')
            ->assertJsonPath('result.error', 'RUNTIME_TIMEOUT')
            ->assertJsonPath('result.error_layer', 'transport');

        $message = OutboundMessage::query()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame('failed', $message->status);
        $this->assertSame('RUNTIME_TIMEOUT', $message->failure_reason);
    }

    public function test_runtime_send_test_invalid_response_failure_is_structured_and_persisted(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => '515031234567890',
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_send_path', '/send');

        Http::fake([
            'http://python-engine.test/send' => Http::response([
                'status' => 'ok',
                'message_id' => 'py-invalid-1',
            ], 200),
        ]);

        $this->actingAs($user)
            ->postJson('/dashboard/api/runtime/python/send-test', [
                'sim_id' => $sim->id,
                'customer_phone' => '09171234567',
                'message' => 'runtime send test',
            ])
            ->assertStatus(502)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('result.status', 'failed')
            ->assertJsonPath('result.error', 'INVALID_RESPONSE')
            ->assertJsonPath('result.error_layer', 'python_api');

        $message = OutboundMessage::query()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame('failed', $message->status);
        $this->assertSame('INVALID_RESPONSE', $message->failure_reason);
    }
}
