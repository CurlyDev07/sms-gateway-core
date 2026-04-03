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
}

