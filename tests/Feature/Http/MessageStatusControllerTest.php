<?php

namespace Tests\Feature\Http;

use App\Models\OutboundMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class MessageStatusControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_returns_message_status_by_client_message_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 10:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'uuid'              => (string) Str::uuid(),
            'company_id'        => $company->id,
            'sim_id'            => $sim->id,
            'customer_phone'    => '09171234567',
            'message'           => 'Hello',
            'message_type'      => 'CHAT',
            'status'            => 'sent',
            'client_message_id' => 'ref-abc-001',
            'retry_count'       => 0,
            'queued_at'         => Carbon::parse('2026-04-06 09:55:00'),
            'sent_at'           => Carbon::parse('2026-04-06 09:56:00'),
            'failed_at'         => null,
            'failure_reason'    => null,
            'scheduled_at'      => null,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/messages/status?client_message_id=ref-abc-001');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $message->id)
            ->assertJsonPath('messages.0.client_message_id', 'ref-abc-001')
            ->assertJsonPath('messages.0.sim_id', $sim->id)
            ->assertJsonPath('messages.0.customer_phone', '09171234567')
            ->assertJsonPath('messages.0.message_type', 'CHAT')
            ->assertJsonPath('messages.0.status', 'sent')
            ->assertJsonPath('messages.0.retry_count', 0)
            ->assertJsonPath('messages.0.queued_at', '2026-04-06T09:55:00+00:00')
            ->assertJsonPath('messages.0.sent_at', '2026-04-06T09:56:00+00:00')
            ->assertJsonPath('messages.0.failed_at', null)
            ->assertJsonPath('messages.0.failure_reason', null)
            ->assertJsonPath('messages.0.scheduled_at', null);
    }

    /** @test */
    public function it_returns_only_messages_belonging_to_the_authenticated_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $simA = $this->createSim($companyA);
        $simB = $this->createSim($companyB);

        OutboundMessage::query()->create([
            'uuid'              => (string) Str::uuid(),
            'company_id'        => $companyA->id,
            'sim_id'            => $simA->id,
            'customer_phone'    => '09171111111',
            'message'           => 'A',
            'message_type'      => 'CHAT',
            'status'            => 'sent',
            'client_message_id' => 'ref-shared-id',
            'retry_count'       => 0,
        ]);

        OutboundMessage::query()->create([
            'uuid'              => (string) Str::uuid(),
            'company_id'        => $companyB->id,
            'sim_id'            => $simB->id,
            'customer_phone'    => '09172222222',
            'message'           => 'B',
            'message_type'      => 'CHAT',
            'status'            => 'sent',
            'client_message_id' => 'ref-shared-id',
            'retry_count'       => 0,
        ]);

        [$apiClient, $secret] = $this->createApiClient($companyA);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/messages/status?client_message_id=ref-shared-id');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.customer_phone', '09171111111');
    }

    /** @test */
    public function it_returns_422_when_client_message_id_is_missing(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/messages/status');

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'client_message_id is required');
    }

    /** @test */
    public function it_returns_empty_messages_array_when_no_match_found(): void
    {
        $company = $this->createCompany();
        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/messages/status?client_message_id=does-not-exist');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJson(['messages' => []]);
    }

    /** @test */
    public function it_filters_by_sim_id_when_provided(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);

        OutboundMessage::query()->create([
            'uuid'              => (string) Str::uuid(),
            'company_id'        => $company->id,
            'sim_id'            => $simA->id,
            'customer_phone'    => '09171111111',
            'message'           => 'via sim A',
            'message_type'      => 'CHAT',
            'status'            => 'sent',
            'client_message_id' => 'ref-multi-sim',
            'retry_count'       => 0,
        ]);

        OutboundMessage::query()->create([
            'uuid'              => (string) Str::uuid(),
            'company_id'        => $company->id,
            'sim_id'            => $simB->id,
            'customer_phone'    => '09171111111',
            'message'           => 'via sim B',
            'message_type'      => 'CHAT',
            'status'            => 'sent',
            'client_message_id' => 'ref-multi-sim',
            'retry_count'       => 0,
        ]);

        [$apiClient, $secret] = $this->createApiClient($company);

        $response = $this->withHeaders($this->authHeaders($apiClient->api_key, $secret))
            ->getJson('/api/messages/status?client_message_id=ref-multi-sim&sim_id='.$simA->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.sim_id', $simA->id);
    }

    /** @test */
    public function it_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/messages/status?client_message_id=anything');

        $response->assertStatus(401);
    }

    /**
     * @param string $apiKey
     * @param string $apiSecret
     * @return array<string, string>
     */
    private function authHeaders(string $apiKey, string $apiSecret): array
    {
        return [
            'X-API-KEY'    => $apiKey,
            'X-API-SECRET' => $apiSecret,
        ];
    }
}
