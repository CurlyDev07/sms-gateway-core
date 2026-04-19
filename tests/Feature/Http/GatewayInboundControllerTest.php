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

        $storedIdempotencyKey = hash('sha256', '515039219149367|inbound-key-001');

        $this->assertDatabaseHas('inbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '515039219149367',
            'customer_phone' => '09171112222',
            'idempotency_key' => $storedIdempotencyKey,
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
    public function it_does_not_false_dedupe_same_idempotency_key_across_different_sims(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $simA = $this->createSim($company, ['imsi' => '515020241752004']);
        $simB = $this->createSim($company, ['imsi' => '515020241752005']);

        $payloadA = [
            'runtime_sim_id' => '515020241752004',
            'customer_phone' => '09278986797',
            'message' => 'SIM A inbound',
            'received_at' => now()->toIso8601String(),
            'idempotency_key' => 'cross-sim-idem-001',
        ];

        $payloadB = [
            'runtime_sim_id' => '515020241752005',
            'customer_phone' => '09278986797',
            'message' => 'SIM B inbound',
            'received_at' => now()->toIso8601String(),
            'idempotency_key' => 'cross-sim-idem-001',
        ];

        $first = $this->postJson('/api/gateway/inbound', $payloadA);
        $second = $this->postJson('/api/gateway/inbound', $payloadB);

        $first->assertStatus(200)->assertJsonPath('ok', true);
        $second->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonMissing(['duplicate' => true]);

        $this->assertDatabaseHas('inbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $simA->id,
            'runtime_sim_id' => '515020241752004',
            'idempotency_key' => hash('sha256', '515020241752004|cross-sim-idem-001'),
        ]);

        $this->assertDatabaseHas('inbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $simB->id,
            'runtime_sim_id' => '515020241752005',
            'idempotency_key' => hash('sha256', '515020241752005|cross-sim-idem-001'),
        ]);

        $this->assertSame(2, InboundMessage::query()->count());
        Queue::assertPushed(RelayInboundMessageJob::class, 2);
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
    public function it_accepts_python_legacy_payload_with_from_and_string_imsi_sim_id(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => '515039219149367',
        ]);

        $response = $this->postJson('/api/gateway/inbound', [
            'sim_id' => '515039219149367',
            'from' => '+639550090156',
            'message' => 'Legacy python payload',
            'received_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('inbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '515039219149367',
            'customer_phone' => '09550090156',
            'message' => 'Legacy python payload',
        ]);

        Queue::assertPushed(RelayInboundMessageJob::class, 1);
    }

    /** @test */
    public function it_resolves_runtime_sim_id_via_slot_name_fallback_when_imsi_is_not_available(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => null,
            'slot_name' => '3-7.4.3',
        ]);

        $response = $this->postJson('/api/gateway/inbound', [
            'runtime_sim_id' => '3-7.4.3',
            'customer_phone' => '09278986797',
            'message' => 'slot-fallback',
            'received_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('inbound_messages', [
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => '3-7.4.3',
            'customer_phone' => '09278986797',
            'message' => 'slot-fallback',
        ]);
    }

    /** @test */
    public function it_creates_sticky_assignment_from_inbound_when_customer_has_no_prior_assignment(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'imsi' => '515020241752005',
        ]);

        $response = $this->postJson('/api/gateway/inbound', [
            'runtime_sim_id' => '515020241752005',
            'customer_phone' => '+639278986797',
            'message' => 'First inbound',
            'received_at' => now()->toIso8601String(),
            'idempotency_key' => 'inbound-create-sticky-001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('customer_sim_assignments', [
            'company_id' => $company->id,
            'customer_phone' => '09278986797',
            'sim_id' => $sim->id,
            'status' => 'active',
            'has_replied' => 1,
        ]);
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
