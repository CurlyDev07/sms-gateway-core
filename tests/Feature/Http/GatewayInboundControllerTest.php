<?php

namespace Tests\Feature\Http;

use App\Jobs\RelayInboundMessageJob;
use App\Models\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class GatewayInboundControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_accepts_runtime_sim_id_and_resolves_to_tenant_sim_id(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => '515039219149367',
        ]);

        $this->createAssignment($company, $sim, '09171112222');

        $response = $this->postJson('/api/gateway/inbound', [
            'runtime_sim_id' => '515039219149367',
            'customer_phone' => '09171112222',
            'message' => 'Inbound hello',
            'received_at' => now()->toIso8601String(),
            'idempotency_key' => 'inbound-key-001',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'queued_for_relay' => true,
                'idempotency_key' => 'inbound-key-001',
            ]);

        $this->assertDatabaseHas('inbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '515039219149367',
            'customer_phone' => '09171112222',
            'idempotency_key' => 'inbound-key-001',
            'relay_status' => 'pending',
            'relayed_to_chat_app' => 0,
        ]);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'company_id' => $company->id,
            'customer_phone' => '09171112222',
            'has_replied' => 1,
        ]);

        Queue::assertPushed(RelayInboundMessageJob::class, 1);
    }

    /** @test */
    public function it_dedupes_retries_using_idempotency_key(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => '515039219149367',
        ]);

        $payload = [
            'runtime_sim_id' => '515039219149367',
            'customer_phone' => '09173334444',
            'message' => 'Duplicate test',
            'received_at' => now()->toIso8601String(),
            'idempotency_key' => 'inbound-retry-dup-001',
        ];

        $first = $this->postJson('/api/gateway/inbound', $payload);
        $second = $this->postJson('/api/gateway/inbound', $payload);

        $first->assertStatus(200)->assertJsonPath('ok', true);
        $second->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('idempotency_key', 'inbound-retry-dup-001');

        $this->assertSame(1, InboundMessage::query()->count());
        Queue::assertPushed(RelayInboundMessageJob::class, 1);
    }

    /** @test */
    public function it_remains_backward_compatible_with_integer_sim_id_payloads(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $response = $this->postJson('/api/gateway/inbound', [
            'sim_id' => $sim->id,
            'customer_phone' => '09175556666',
            'message' => 'Legacy payload',
            'received_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('inbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => null,
            'customer_phone' => '09175556666',
        ]);

        Queue::assertPushed(RelayInboundMessageJob::class, 1);
    }

    /** @test */
    public function it_returns_ack_error_when_no_supported_sim_identifier_is_present(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/gateway/inbound', [
            'customer_phone' => '09170000001',
            'message' => 'No identifier',
            'received_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => false,
                'error' => 'sim_identifier_missing',
            ]);

        $this->assertSame(0, InboundMessage::query()->count());
        Queue::assertNothingPushed();
    }
}

