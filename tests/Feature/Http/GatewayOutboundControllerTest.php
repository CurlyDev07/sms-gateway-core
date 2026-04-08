<?php

namespace Tests\Feature\Http;

use App\Models\OutboundMessage;
use App\Services\RedisQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class GatewayOutboundControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function active_sim_returns_200_and_saves_pending_message(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $redisQueueService = $this->mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('enqueue')
            ->once()
            ->with(
                (int) $sim->id,
                Mockery::on(fn ($messageId) => is_int($messageId) && $messageId > 0),
                'CHAT'
            );

        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $this->createAssignment($company, $sim, '09171234567');

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/send', [
                'customer_phone' => '09171234567',
                'message' => 'Hello active',
                'message_type' => 'CHAT',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'status' => 'queued',
                'queued' => true,
                'sim_id' => $sim->id,
            ]);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09171234567',
            'status' => 'queued',
            'message_type' => 'CHAT',
        ]);
    }

    /** @test */
    public function paused_sim_returns_202_and_saves_non_enqueued_message(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'paused',
            'status' => 'active',
        ]);

        $redisQueueService = $this->mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('enqueue')->never();

        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $this->createAssignment($company, $sim, '09171234568');

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/send', [
                'customer_phone' => '09171234568',
                'message' => 'Hello paused',
                'message_type' => 'AUTO_REPLY',
            ]);

        $response->assertStatus(202)
            ->assertJson([
                'ok' => true,
                'status' => 'accepted',
                'queued' => false,
                'sim_id' => $sim->id,
            ]);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09171234568',
            'status' => 'pending',
            'message_type' => 'AUTO_REPLY',
        ]);
    }

    /** @test */
    public function blocked_sim_returns_503_and_does_not_save_message(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'blocked',
            'status' => 'active',
        ]);

        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $this->createAssignment($company, $sim, '09171234569');

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/send', [
                'customer_phone' => '09171234569',
                'message' => 'Hello blocked',
                'message_type' => 'FOLLOW_UP',
            ]);

        $response->assertStatus(503)
            ->assertJson([
                'ok' => false,
                'error' => 'sim_blocked',
            ]);

        $this->assertDatabaseMissing('outbound_messages', [
            'company_id' => $company->id,
            'customer_phone' => '09171234569',
            'message' => 'Hello blocked',
        ]);
    }

    /** @test */
    public function outbound_intake_requires_api_client_authentication(): void
    {
        $response = $this->postJson('/api/messages/send', [
            'customer_phone' => '09170000001',
            'message' => 'No auth',
            'message_type' => 'CHAT',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'ok' => false,
                'error' => 'unauthorized',
            ]);
    }

    /** @test */
    public function tenant_mismatch_in_request_body_is_rejected_before_intake(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA']);
        $companyB = $this->createCompany(['code' => 'CMPB']);

        $sim = $this->createSim($companyA, [
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        [$apiClient, $plainSecret] = $this->createApiClient($companyA);

        $this->createAssignment($companyA, $sim, '09170000002');

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/send', [
                'company_id' => $companyB->id,
                'customer_phone' => '09170000002',
                'message' => 'Mismatch attempt',
                'message_type' => 'BLAST',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'error' => 'forbidden',
            ]);

        $this->assertSame(0, OutboundMessage::query()->count());
    }

    /** @test */
    public function bulk_returns_per_item_results_for_mixed_outcomes(): void
    {
        $company = $this->createCompany();

        $activeSim = $this->createSim($company, [
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $pausedSim = $this->createSim($company, [
            'operator_status' => 'paused',
            'status' => 'active',
        ]);

        $blockedSim = $this->createSim($company, [
            'operator_status' => 'blocked',
            'status' => 'active',
        ]);

        $this->createAssignment($company, $activeSim, '09171111111');
        $this->createAssignment($company, $pausedSim, '09172222222');
        $this->createAssignment($company, $blockedSim, '09173333333');

        $redisQueueService = $this->mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('enqueue')
            ->once()
            ->with(
                (int) $activeSim->id,
                Mockery::on(fn ($messageId) => is_int($messageId) && $messageId > 0),
                'CHAT'
            );

        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/bulk', [
                'messages' => [
                    [
                        'customer_phone' => '09171111111',
                        'message' => 'Bulk active',
                        'message_type' => 'CHAT',
                        'client_message_id' => 'bulk-active-001',
                    ],
                    [
                        'customer_phone' => '09172222222',
                        'message' => 'Bulk paused',
                        'message_type' => 'FOLLOW_UP',
                        'client_message_id' => 'bulk-paused-001',
                    ],
                    [
                        'customer_phone' => '09173333333',
                        'message' => 'Bulk blocked',
                        'message_type' => 'BLAST',
                        'client_message_id' => 'bulk-blocked-001',
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'summary' => [
                    'total' => 3,
                    'succeeded' => 2,
                    'failed' => 1,
                ],
            ])
            ->assertJsonCount(3, 'results');

        $results = collect($response->json('results'))->keyBy('client_message_id');

        $this->assertSame('queued', $results['bulk-active-001']['status']);
        $this->assertTrue((bool) $results['bulk-active-001']['queued']);
        $this->assertNull($results['bulk-active-001']['error']);
        $this->assertNotNull($results['bulk-active-001']['message_id']);

        $this->assertSame('accepted', $results['bulk-paused-001']['status']);
        $this->assertFalse((bool) $results['bulk-paused-001']['queued']);
        $this->assertNull($results['bulk-paused-001']['error']);
        $this->assertNotNull($results['bulk-paused-001']['message_id']);

        $this->assertSame('failed', $results['bulk-blocked-001']['status']);
        $this->assertSame('sim_blocked', $results['bulk-blocked-001']['error']);
        $this->assertNull($results['bulk-blocked-001']['message_id']);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $activeSim->id,
            'client_message_id' => 'bulk-active-001',
            'status' => 'queued',
        ]);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'client_message_id' => 'bulk-paused-001',
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('outbound_messages', [
            'company_id' => $company->id,
            'client_message_id' => 'bulk-blocked-001',
        ]);
    }

    /** @test */
    public function bulk_item_validation_failure_does_not_stop_other_items(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $this->createAssignment($company, $sim, '09174444444');

        $redisQueueService = $this->mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('enqueue')
            ->once()
            ->with(
                (int) $sim->id,
                Mockery::on(fn ($messageId) => is_int($messageId) && $messageId > 0),
                'CHAT'
            );

        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/bulk', [
                'messages' => [
                    [
                        'message' => 'Invalid item missing phone and type',
                        'client_message_id' => 'bulk-invalid-001',
                    ],
                    [
                        'customer_phone' => '09174444444',
                        'message' => 'Valid item',
                        'message_type' => 'CHAT',
                        'client_message_id' => 'bulk-valid-001',
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'summary' => [
                    'total' => 2,
                    'succeeded' => 1,
                    'failed' => 1,
                ],
            ]);

        $results = collect($response->json('results'))->keyBy('client_message_id');

        $this->assertSame('failed', $results['bulk-invalid-001']['status']);
        $this->assertSame('validation_failed', $results['bulk-invalid-001']['error']);

        $this->assertSame('queued', $results['bulk-valid-001']['status']);
        $this->assertNull($results['bulk-valid-001']['error']);
        $this->assertNotNull($results['bulk-valid-001']['message_id']);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'client_message_id' => 'bulk-valid-001',
            'status' => 'queued',
        ]);

        $this->assertDatabaseMissing('outbound_messages', [
            'company_id' => $company->id,
            'client_message_id' => 'bulk-invalid-001',
        ]);
    }

    /** @test */
    public function bulk_requires_messages_array_payload(): void
    {
        $company = $this->createCompany();
        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $plainSecret))
            ->postJson('/api/messages/bulk', [
                'messages' => [],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'error' => 'validation_failed',
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $apiKey, string $apiSecret): array
    {
        return [
            'X-API-KEY' => $apiKey,
            'X-API-SECRET' => $apiSecret,
        ];
    }
}
