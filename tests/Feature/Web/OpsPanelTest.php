<?php

namespace Tests\Feature\Web;

use App\Models\ApiClient;
use App\Models\InboundMessage;
use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class OpsPanelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_health_path', '/health');
        config()->set('sms.python_api_discover_path', '/modems/discover');

        Http::fake([
            'http://python-engine.test/health' => Http::response([
                'ok' => true,
            ], 200),
            'http://python-engine.test/modems/discover' => Http::response([
                'modems' => [
                    [
                        'sim_id' => '515039219149367',
                        'port' => '/dev/ttyUSB2',
                        'effective_send_ready' => true,
                        'at_ok' => true,
                        'sim_ready' => true,
                        'creg_registered' => true,
                    ],
                ],
            ], 200),
        ]);
    }

    /** @test */
    public function ops_page_renders_without_auth(): void
    {
        $response = $this->get('/ops');

        $response->assertStatus(200);
        $response->assertSee('Ops Pipeline Monitor');
    }

    /** @test */
    public function ops_data_returns_expected_tables_and_summary_keys(): void
    {
        $company = $this->createCompany(['code' => 'OPS1']);
        $sim = $this->createSim($company, [
            'imsi' => '515039219149367',
        ]);

        InboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '515039219149367',
            'customer_phone' => '+639278986797',
            'message' => 'Inbound test',
            'received_at' => now(),
            'idempotency_key' => 'ops-test-inbound-1',
            'relay_status' => 'success',
            'relayed_to_chat_app' => true,
            'relayed_at' => now(),
        ]);

        OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09278986797',
            'message' => 'Outbound test',
            'message_type' => 'CHAT',
            'priority' => 10,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        ApiClient::query()->create([
            'company_id' => $company->id,
            'name' => 'Ops Client',
            'api_key' => 'ops-key',
            'api_secret' => 'ops-secret',
            'status' => 'active',
        ]);

        $response = $this->getJson('/ops/data');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'ok',
                'generated_at',
                'pipeline_hint' => ['layer', 'severity', 'message'],
                'summary' => [
                    'inbound_1h',
                    'outbound_1h',
                    'relay',
                    'queues',
                    'runtime',
                    'entities',
                ],
                'settings' => [
                    'health_policy',
                ],
                'runtime' => [
                    'health',
                    'discovery' => ['ok', 'status', 'error', 'modems'],
                ],
                'tables' => [
                    'sims',
                    'inbound_recent',
                    'outbound_recent',
                    'webhook_recent',
                    'api_clients',
                ],
            ]);

        $payload = $response->json();
        $this->assertNotEmpty($payload['tables']['sims']);
        $this->assertNotEmpty($payload['tables']['inbound_recent']);
        $this->assertNotEmpty($payload['tables']['outbound_recent']);
        $this->assertNotEmpty($payload['tables']['api_clients']);
    }

    /** @test */
    public function ops_health_policy_settings_can_be_updated_from_panel_endpoint(): void
    {
        $payload = [
            'sim_health_unhealthy_threshold_minutes' => 180,
            'sim_health_runtime_failure_window_minutes' => 20,
            'sim_health_runtime_failure_threshold' => 4,
            'sim_health_runtime_suppression_minutes' => 30,
            'runtime_sync_disable_after_not_ready_checks' => 3,
            'runtime_sync_enable_after_ready_checks' => 2,
        ];

        $response = $this->postJson('/ops/settings/health-policy', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('health_policy.sim_health_unhealthy_threshold_minutes', 180)
            ->assertJsonPath('health_policy.runtime_sync_disable_after_not_ready_checks', 3)
            ->assertJsonPath('health_policy.runtime_sync_enable_after_ready_checks', 2);

        $this->assertDatabaseHas('gateway_settings', [
            'key' => 'sim_health_unhealthy_threshold_minutes',
            'value' => '180',
        ]);

        $this->assertDatabaseHas('gateway_settings', [
            'key' => 'runtime_sync_disable_after_not_ready_checks',
            'value' => '3',
        ]);
    }
}
