<?php

namespace Tests\Feature\Web;

use App\Models\ApiClient;
use App\Models\InboundMessage;
use App\Models\OutboundMessage;
use App\Jobs\RetryInboundRelayJob;
use App\Services\RedisQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
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
    public function retry_all_inbound_resets_failed_rows_and_dispatches_retries(): void
    {
        Queue::fake();

        $company = $this->createCompany(['code' => 'OPS2']);
        $sim = $this->createSim($company, [
            'imsi' => '515020241752004',
        ]);

        $failed = InboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '515020241752004',
            'customer_phone' => '09278986797',
            'message' => 'Failed inbound',
            'received_at' => now(),
            'relay_status' => 'failed',
            'relay_retry_count' => 3,
            'relay_failed_at' => now(),
            'relay_error' => 'HTTP 500',
            'relay_next_attempt_at' => now()->subMinute(),
        ]);

        $pending = InboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '515020241752004',
            'customer_phone' => '09278986797',
            'message' => 'Pending inbound',
            'received_at' => now(),
            'relay_status' => 'pending',
            'relay_retry_count' => 1,
            'relay_next_attempt_at' => now()->addMinutes(10),
        ]);

        $success = InboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '515020241752004',
            'customer_phone' => '09278986797',
            'message' => 'Success inbound',
            'received_at' => now(),
            'relay_status' => 'success',
            'relayed_to_chat_app' => true,
            'relayed_at' => now(),
        ]);

        $response = $this->postJson('/ops/retry-all-inbound', [
            'limit' => 500,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reset_count', 2)
            ->assertJsonPath('dispatched', 2);

        $this->assertSame('pending', $failed->fresh()->relay_status);
        $this->assertSame(0, (int) $failed->fresh()->relay_retry_count);
        $this->assertNull($failed->fresh()->relay_failed_at);
        $this->assertSame('pending', $pending->fresh()->relay_status);
        $this->assertSame(0, (int) $pending->fresh()->relay_retry_count);
        $this->assertSame('success', $success->fresh()->relay_status);

        Queue::assertPushed(RetryInboundRelayJob::class, 2);
    }

    /** @test */
    public function retry_all_outbound_resets_rows_and_enqueues_now(): void
    {
        $company = $this->createCompany(['code' => 'OPS3']);
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $failed = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09278986797',
            'message' => 'Failed outbound',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'failed',
            'retry_count' => 1,
            'failure_reason' => 'SEND_FAILED',
        ]);

        $queued = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09550090156',
            'message' => 'Queued outbound',
            'message_type' => 'FOLLOW_UP',
            'priority' => 50,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $mock = Mockery::mock(RedisQueueService::class);
        $mock->shouldReceive('enqueue')->twice();
        $this->app->instance(RedisQueueService::class, $mock);

        $response = $this->postJson('/ops/retry-all-outbound', [
            'limit' => 500,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reset_count', 2)
            ->assertJsonPath('enqueued', 2);

        $failedFresh = $failed->fresh();
        $queuedFresh = $queued->fresh();

        $this->assertSame('queued', $failedFresh->status);
        $this->assertNull($failedFresh->scheduled_at);
        $this->assertNotNull($failedFresh->queued_at);

        $this->assertSame('queued', $queuedFresh->status);
        $this->assertNull($queuedFresh->scheduled_at);
        $this->assertNotNull($queuedFresh->queued_at);
    }
}
