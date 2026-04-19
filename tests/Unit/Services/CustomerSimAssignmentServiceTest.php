<?php

namespace Tests\Unit\Services;

use App\Services\CustomerSimAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class CustomerSimAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function reassign_sim_is_disabled_and_requires_manual_migration_commands(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);
        $assignment = $this->createAssignment($company, $sim, '09175000100');

        /** @var \App\Services\CustomerSimAssignmentService $service */
        $service = app(CustomerSimAssignmentService::class);

        try {
            $service->reassignSim($company->id, '09175000100');
            $this->fail('Expected RuntimeException for disabled automatic reassignment.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Automatic SIM reassignment is disabled. Use manual migration commands instead.',
                $e->getMessage()
            );
        }

        $this->assertSame($sim->id, (int) $assignment->fresh()->sim_id);
        $this->assertSame('active', (string) $assignment->fresh()->status);
    }

    /** @test */
    public function mark_replied_creates_assignment_when_missing_and_sim_id_is_provided(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        /** @var \App\Services\CustomerSimAssignmentService $service */
        $service = app(CustomerSimAssignmentService::class);

        $assignment = $service->markReplied($company->id, '+639278986797', $sim->id);

        $this->assertNotNull($assignment);
        $this->assertSame($company->id, (int) $assignment->company_id);
        $this->assertSame($sim->id, (int) $assignment->sim_id);
        $this->assertSame('09278986797', (string) $assignment->customer_phone);
        $this->assertTrue((bool) $assignment->has_replied);
    }

    /** @test */
    public function mark_replied_repins_assignment_to_latest_inbound_sim_when_not_locked(): void
    {
        $company = $this->createCompany();
        $simA = $this->createSim($company);
        $simB = $this->createSim($company);
        $assignment = $this->createAssignment($company, $simA, '09278986797');

        /** @var \App\Services\CustomerSimAssignmentService $service */
        $service = app(CustomerSimAssignmentService::class);

        $updated = $service->markReplied($company->id, '+639278986797', $simB->id);

        $this->assertNotNull($updated);
        $this->assertSame($assignment->id, (int) $updated->id);
        $this->assertSame($simB->id, (int) $updated->fresh()->sim_id);
        $this->assertTrue((bool) $updated->fresh()->has_replied);
    }
}
