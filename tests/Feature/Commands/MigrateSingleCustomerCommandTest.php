<?php

namespace Tests\Feature\Commands;

use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class MigrateSingleCustomerCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_migrates_single_customer_successfully(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company);
        $customerPhone = '09171000100';

        $assignment = $this->createAssignment($company, $fromSim, $customerPhone);
        $pending = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'pending');
        $queued = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'queued');
        $sending = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'sending');

        $this->artisan('gateway:migrate-single-customer', [
            'company_id' => $company->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
            'customer_phone' => $customerPhone,
        ])->expectsOutput('Single-customer migration completed.')
            ->expectsOutput('Assignments moved: 1')
            ->expectsOutput('Messages moved: 2')
            ->assertExitCode(0);

        $this->assertSame($toSim->id, $assignment->fresh()->sim_id);
        $this->assertSame($toSim->id, $pending->fresh()->sim_id);
        $this->assertSame($toSim->id, $queued->fresh()->sim_id);
        $this->assertSame($fromSim->id, $sending->fresh()->sim_id);
    }

    /** @test */
    public function it_handles_single_customer_noop_when_nothing_is_eligible(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company);
        $customerPhone = '09171000101';

        $sending = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'sending');

        $this->artisan('gateway:migrate-single-customer', [
            'company_id' => $company->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
            'customer_phone' => $customerPhone,
        ])->expectsOutput('Single-customer migration completed.')
            ->expectsOutput('Assignments moved: 0')
            ->expectsOutput('Messages moved: 0')
            ->assertExitCode(0);

        $this->assertSame($fromSim->id, $sending->fresh()->sim_id);
    }

    /** @test */
    public function it_rejects_source_company_mismatch_for_single_customer_migration(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA1']);
        $companyB = $this->createCompany(['code' => 'CMPB1']);
        $fromSim = $this->createSim($companyB);
        $toSim = $this->createSim($companyA);

        $this->artisan('gateway:migrate-single-customer', [
            'company_id' => $companyA->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
            'customer_phone' => '09171000102',
        ])->expectsOutput('Source SIM does not belong to the provided company.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_rejects_destination_company_mismatch_for_single_customer_migration(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA2']);
        $companyB = $this->createCompany(['code' => 'CMPB2']);
        $fromSim = $this->createSim($companyA);
        $toSim = $this->createSim($companyB);

        $this->artisan('gateway:migrate-single-customer', [
            'company_id' => $companyA->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
            'customer_phone' => '09171000103',
        ])->expectsOutput('Destination SIM does not belong to the provided company.')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_rejects_blocked_destination_for_single_customer_migration(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company, ['operator_status' => 'blocked']);

        $this->artisan('gateway:migrate-single-customer', [
            'company_id' => $company->id,
            'from_sim_id' => $fromSim->id,
            'to_sim_id' => $toSim->id,
            'customer_phone' => '09171000104',
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
            'message' => 'Command single test '.$status,
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => $status,
        ]);
    }
}

