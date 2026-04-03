<?php

namespace Tests\Feature\Commands;

use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class NormalizePausedQueuedToPendingCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_normalizes_only_paused_sim_queued_rows_with_null_queued_at(): void
    {
        $company = $this->createCompany();
        $pausedSim = $this->createSim($company, ['operator_status' => 'paused']);
        $activeSim = $this->createSim($company, ['operator_status' => 'active']);

        $target = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'status' => 'queued',
            'queued_at' => null,
            'locked_at' => null,
            'sent_at' => null,
            'failed_at' => null,
        ]);

        $queuedWithQueuedAt = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $queuedWithLock = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'status' => 'queued',
            'queued_at' => null,
            'locked_at' => now(),
        ]);

        $queuedWithSentAt = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'status' => 'queued',
            'queued_at' => null,
            'sent_at' => now(),
        ]);

        $queuedWithFailedAt = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'status' => 'queued',
            'queued_at' => null,
            'failed_at' => now(),
        ]);

        $sending = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'status' => 'sending',
        ]);

        $activeSimQueued = $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $activeSim->id,
            'status' => 'queued',
            'queued_at' => null,
        ]);

        $this->artisan('gateway:normalize-paused-queued-to-pending', [
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
        ])->expectsOutput('Paused queued normalization completed.')
            ->expectsOutput('Updated rows: 1')
            ->assertExitCode(0);

        $this->assertSame('pending', $target->fresh()->status);
        $this->assertSame('queued', $queuedWithQueuedAt->fresh()->status);
        $this->assertSame('queued', $queuedWithLock->fresh()->status);
        $this->assertSame('queued', $queuedWithSentAt->fresh()->status);
        $this->assertSame('queued', $queuedWithFailedAt->fresh()->status);
        $this->assertSame('sending', $sending->fresh()->status);
        $this->assertSame('queued', $activeSimQueued->fresh()->status);
    }

    /** @test */
    public function it_rejects_active_sim_for_safety(): void
    {
        $company = $this->createCompany();
        $activeSim = $this->createSim($company, ['operator_status' => 'active']);

        $this->artisan('gateway:normalize-paused-queued-to-pending', [
            'company_id' => $company->id,
            'sim_id' => $activeSim->id,
        ])->expectsOutput('Normalization is restricted to paused SIMs only for safety.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_rejects_company_mismatch(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPNA']);
        $companyB = $this->createCompany(['code' => 'CMPNB']);
        $sim = $this->createSim($companyB, ['operator_status' => 'paused']);

        $this->artisan('gateway:normalize-paused-queued-to-pending', [
            'company_id' => $companyA->id,
            'sim_id' => $sim->id,
        ])->expectsOutput('SIM does not belong to provided company.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_handles_noop_when_no_legacy_rows_match(): void
    {
        $company = $this->createCompany();
        $pausedSim = $this->createSim($company, ['operator_status' => 'paused']);

        $this->createOutboundMessage([
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $this->artisan('gateway:normalize-paused-queued-to-pending', [
            'company_id' => $company->id,
            'sim_id' => $pausedSim->id,
        ])->expectsOutput('No legacy queued rows found for normalization.')
            ->expectsOutput('Updated rows: 0')
            ->assertExitCode(0);
    }

    private function createOutboundMessage(array $attributes): OutboundMessage
    {
        return OutboundMessage::query()->create(array_merge([
            'customer_phone' => '09178000000',
            'message' => 'normalize-test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'pending',
        ], $attributes));
    }
}

