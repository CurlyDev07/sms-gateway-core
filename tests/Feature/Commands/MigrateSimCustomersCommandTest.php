<?php

namespace Tests\Feature\Commands;

use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class MigrateSimCustomersCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_migrates_bulk_customers_successfully(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company);

        $assignmentA = $this->createAssignment($company, $fromSim, '09172000100');
        $assignmentB = $this->createAssignment($company, $fromSim, '09172000101');

        $pending = $this->createOutboundMessage($company->id, $fromSim->id, '09172000100', 'pending');
        $queued = $this->createOutboundMessage($company->id, $fromSim->id, '09172000101', 'queued');
        $sending = $this->createOutboundMessage($company->id, $fromSim->id, '09172000100', 'sending');

        $this->artisan('gateway:migrate-sim-customers', [
            'company_id' => $company->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
        ])->expectsOutput('Bulk SIM migration completed.')
            ->expectsOutput('Assignments moved: 2')
            ->expectsOutput('Messages moved: 2')
            ->assertExitCode(0);

        $this->assertSame($toSim->id, $assignmentA->fresh()->sim_id);
        $this->assertSame($toSim->id, $assignmentB->fresh()->sim_id);
        $this->assertSame($toSim->id, $pending->fresh()->sim_id);
        $this->assertSame($toSim->id, $queued->fresh()->sim_id);
        $this->assertSame($fromSim->id, $sending->fresh()->sim_id);
    }

    /** @test */
    public function it_handles_bulk_noop_when_nothing_is_eligible(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company);

        $sending = $this->createOutboundMessage($company->id, $fromSim->id, '09172000102', 'sending');
        $sent = $this->createOutboundMessage($company->id, $fromSim->id, '09172000103', 'sent');

        $this->artisan('gateway:migrate-sim-customers', [
            'company_id' => $company->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
        ])->expectsOutput('Bulk SIM migration completed.')
            ->expectsOutput('Assignments moved: 0')
            ->expectsOutput('Messages moved: 0')
            ->assertExitCode(0);

        $this->assertSame($fromSim->id, $sending->fresh()->sim_id);
        $this->assertSame($fromSim->id, $sent->fresh()->sim_id);
    }

    /** @test */
    public function it_rejects_source_company_mismatch_for_bulk_migration(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA3']);
        $companyB = $this->createCompany(['code' => 'CMPB3']);
        $fromSim = $this->createSim($companyB);
        $toSim = $this->createSim($companyA);

        $this->artisan('gateway:migrate-sim-customers', [
            'company_id' => $companyA->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
        ])->expectsOutput('Source SIM does not belong to the provided company.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_rejects_destination_company_mismatch_for_bulk_migration(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA4']);
        $companyB = $this->createCompany(['code' => 'CMPB4']);
        $fromSim = $this->createSim($companyA);
        $toSim = $this->createSim($companyB);

        $this->artisan('gateway:migrate-sim-customers', [
            'company_id' => $companyA->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
        ])->expectsOutput('Destination SIM does not belong to the provided company.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_rejects_blocked_destination_for_bulk_migration(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company, ['operator_status' => 'blocked']);

        $this->artisan('gateway:migrate-sim-customers', [
            'company_id' => $company->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
        ])->expectsOutput('Destination SIM is blocked and cannot receive migrated traffic.')
            ->assertExitCode(1);
    }

    private function createOutboundMessage(
        int $companyId,
        int $simId,
        string $customerPhone,
        string $status
    ): OutboundMessage {
        return OutboundMessage::query()->create([
            'company_id' => $companyId,
            'sim_id' => $simId,
            'customer_phone' => $customerPhone,
            'message' => 'Command bulk test '.$status,
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => $status,
        ]);
    }
}

