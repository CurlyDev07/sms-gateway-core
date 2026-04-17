<?php

namespace Tests\Feature\Http;

use App\Models\OutboundMessage;
use App\Services\RedisQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class InfotxtOutboundControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_accepts_infotxt_style_payload_and_returns_status_00(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $this->createAssignment($company, $sim, '09171234567');
        [$apiClient, $plainSecret] = $this->createApiClient($company, [
            'api_key' => 'chatapp-user',
            'plain_secret' => 'chatapp-key',
        ]);

        $redisQueueService = $this->mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('enqueue')
            ->once()
            ->with(
                (int) $sim->id,
                Mockery::on(fn ($messageId) => is_int($messageId) && $messageId > 0),
                'CHAT'
            );

        $response = $this->post('/api/v2/send.php', [
            'UserID' => $apiClient->api_key,
            'ApiKey' => $plainSecret,
            'Mobile' => '09171234567',
            'SMS' => 'Hello from ChatApp',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', '00')
            ->assertJsonStructure(['status', 'smsid']);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09171234567',
            'message' => 'Hello from ChatApp',
            'message_type' => 'CHAT',
            'status' => 'queued',
        ]);
    }

    /** @test */
    public function it_rejects_invalid_infotxt_credentials(): void
    {
        $response = $this->post('/api/v2/send.php', [
            'UserID' => 'bad-user',
            'ApiKey' => 'bad-key',
            'Mobile' => '09170000001',
            'SMS' => 'Will fail',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => '99',
                'message' => 'unauthorized',
            ]);

        $this->assertSame(0, OutboundMessage::query()->count());
    }

    /** @test */
    public function it_maps_blasting_type_to_internal_blast_message_type(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'active',
            'status' => 'active',
        ]);

        $this->createAssignment($company, $sim, '09172222222');
        [$apiClient, $plainSecret] = $this->createApiClient($company, [
            'api_key' => 'chatapp-user-2',
            'plain_secret' => 'chatapp-key-2',
        ]);

        $redisQueueService = $this->mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('enqueue')
            ->once()
            ->with(
                (int) $sim->id,
                Mockery::on(fn ($messageId) => is_int($messageId) && $messageId > 0),
                'BLAST'
            );

        $response = $this->post('/api/v2/send.php', [
            'UserID' => $apiClient->api_key,
            'ApiKey' => $plainSecret,
            'Mobile' => '09172222222',
            'SMS' => 'Blasting payload',
            'Type' => 'BLASTING',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', '00');

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09172222222',
            'message_type' => 'BLAST',
            'status' => 'queued',
        ]);
    }

    /** @test */
    public function paused_sim_returns_status_00_but_saves_as_pending(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'operator_status' => 'paused',
            'status' => 'active',
        ]);

        $this->createAssignment($company, $sim, '09173333333');
        [$apiClient, $plainSecret] = $this->createApiClient($company, [
            'api_key' => 'chatapp-user-3',
            'plain_secret' => 'chatapp-key-3',
        ]);

        $redisQueueService = $this->mock(RedisQueueService::class);
        $redisQueueService->shouldReceive('enqueue')->never();

        $response = $this->post('/api/v2/send.php', [
            'UserID' => $apiClient->api_key,
            'ApiKey' => $plainSecret,
            'Mobile' => '09173333333',
            'SMS' => 'Paused sim test',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', '00')
            ->assertJsonStructure(['status', 'smsid']);

        $this->assertDatabaseHas('outbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09173333333',
            'message_type' => 'CHAT',
            'status' => 'pending',
        ]);
    }
}

