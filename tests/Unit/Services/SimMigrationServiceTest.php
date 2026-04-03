<?php

namespace Tests\Unit\Services;

use App\Models\OutboundMessage;
use App\Services\SimMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimMigrationServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private SimMigrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SimMigrationService::class);
    }

    /** @test */
    public function it_rejects_same_source_and_destination_sim_for_single_migration(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source and destination SIM must be different.');

        $this->service->migrateSingleCustomer($company->id, $sim->id, $sim->id, '09170000100');
    }

    /** @test */
    public function it_rejects_same_source_and_destination_sim_for_bulk_migration(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source and destination SIM must be different.');

        $this->service->migrateBulk($company->id, $sim->id, $sim->id);
    }

    /** @test */
    public function it_rejects_source_sim_from_another_company(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPA']);
        $companyB = $this->createCompany(['code' => 'CMPB']);
        $sourceSim = $this->createSim($companyB);
        $destinationSim = $this->createSim($companyA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source SIM does not belong to the provided company.');

        $this->service->migrateSingleCustomer($companyA->id, $sourceSim->id, $destinationSim->id, '09170000101');
    }

    /** @test */
    public function it_rejects_destination_sim_from_another_company(): void
    {
        $companyA = $this->createCompany(['code' => 'CMPC']);
        $companyB = $this->createCompany(['code' => 'CMPD']);
        $sourceSim = $this->createSim($companyA);
        $destinationSim = $this->createSim($companyB);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Destination SIM does not belong to the provided company.');

        $this->service->migrateSingleCustomer($companyA->id, $sourceSim->id, $destinationSim->id, '09170000102');
    }

    /** @test */
    public function it_rejects_blocked_destination_sim(): void
    {
        $company = $this->createCompany();
        $sourceSim = $this->createSim($company);
        $destinationSim = $this->createSim($company, ['operator_status' => 'blocked']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Destination SIM is blocked and cannot receive migrated traffic.');

        $this->service->migrateSingleCustomer($company->id, $sourceSim->id, $destinationSim->id, '09170000103');
    }

    /** @test */
    public function single_customer_migration_moves_assignment_and_only_pending_or_queued_messages(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company);
        $customerPhone = '09170000110';

        $assignment = $this->createAssignment($company, $fromSim, $customerPhone);

        $pendingMessage = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'pending');
        $queuedMessage = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'queued');
        $sendingMessage = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'sending');
        $sentMessage = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'sent');
        $failedMessage = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'failed');
        $cancelledMessage = $this->createOutboundMessage($company->id, $fromSim->id, $customerPhone, 'cancelled');

        $result = $this->service->migrateSingleCustomer(
            $company->id,
            $fromSim->id,
            $toSim->id,
            $customerPhone
        );

        $this->assertSame(1, $result['assignments_moved']);
        $this->assertSame(2, $result['messages_moved']);

        $this->assertSame($toSim->id, $assignment->fresh()->sim_id);

        $this->assertSame($toSim->id, $pendingMessage->fresh()->sim_id);
        $this->assertSame($toSim->id, $queuedMessage->fresh()->sim_id);

        $this->assertSame($fromSim->id, $sendingMessage->fresh()->sim_id);
        $this->assertSame($fromSim->id, $sentMessage->fresh()->sim_id);
        $this->assertSame($fromSim->id, $failedMessage->fresh()->sim_id);
        $this->assertSame($fromSim->id, $cancelledMessage->fresh()->sim_id);
    }

    /** @test */
    public function bulk_migration_moves_all_assignments_and_only_pending_or_queued_messages(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company);
        $toSim = $this->createSim($company);

        $this->createAssignment($company, $fromSim, '09170000120');
        $this->createAssignment($company, $fromSim, '09170000121');
        $existingDestinationAssignment = $this->createAssignment($company, $toSim, '09170000122');

        $pendingA = $this->createOutboundMessage($company->id, $fromSim->id, '09170000120', 'pending');
        $queuedA = $this->createOutboundMessage($company->id, $fromSim->id, '09170000120', 'queued');
        $pendingB = $this->createOutboundMessage($company->id, $fromSim->id, '09170000121', 'pending');
        $sending = $this->createOutboundMessage($company->id, $fromSim->id, '09170000120', 'sending');
        $sent = $this->createOutboundMessage($company->id, $fromSim->id, '09170000120', 'sent');
        $failed = $this->createOutboundMessage($company->id, $fromSim->id, '09170000121', 'failed');
        $cancelled = $this->createOutboundMessage($company->id, $fromSim->id, '09170000121', 'cancelled');
        $alreadyDestination = $this->createOutboundMessage($company->id, $toSim->id, '09170000123', 'pending');

        $result = $this->service->migrateBulk($company->id, $fromSim->id, $toSim->id);

        $this->assertSame(2, $result['assignments_moved']);
        $this->assertSame(3, $result['messages_moved']);

        $this->assertSame($toSim->id, $pendingA->fresh()->sim_id);
        $this->assertSame($toSim->id, $queuedA->fresh()->sim_id);
        $this->assertSame($toSim->id, $pendingB->fresh()->sim_id);

        $this->assertSame($fromSim->id, $sending->fresh()->sim_id);
        $this->assertSame($fromSim->id, $sent->fresh()->sim_id);
        $this->assertSame($fromSim->id, $failed->fresh()->sim_id);
        $this->assertSame($fromSim->id, $cancelled->fresh()->sim_id);
        $this->assertSame($toSim->id, $alreadyDestination->fresh()->sim_id);
        $this->assertSame($toSim->id, $existingDestinationAssignment->fresh()->sim_id);
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
            'message' => 'Test message '.$status,
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => $status,
        ]);
    }
}
