<?php

namespace Tests\Unit\Services;

use App\Models\OutboundMessage;
use App\Services\SimSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimSelectionServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private SimSelectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SimSelectionService::class);
    }

    /** @test */
    public function select_available_sim_for_new_assignment_enforces_phase_zero_filters(): void
    {
        $company = $this->createCompany();

        $eligible = $this->createSim($company, [
            'accept_new_assignments' => true,
            'disabled_for_new_assignments' => false,
            'operator_status' => 'active',
            'last_sent_at' => now()->subMinutes(5),
        ]);

        $notAccepting = $this->createSim($company, [
            'accept_new_assignments' => false,
            'disabled_for_new_assignments' => false,
        ]);

        $healthDisabled = $this->createSim($company, [
            'accept_new_assignments' => true,
            'disabled_for_new_assignments' => true,
        ]);

        $blocked = $this->createSim($company, [
            'accept_new_assignments' => true,
            'disabled_for_new_assignments' => false,
            'operator_status' => 'blocked',
        ]);

        OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $eligible->id,
            'customer_phone' => '09170000010',
            'message' => 'Load message',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'pending',
        ]);

        $selected = $this->service->selectAvailableSim($company->id);

        $this->assertNotNull($selected);
        $this->assertSame($eligible->id, $selected->id);
        $this->assertNotSame($notAccepting->id, $selected->id);
        $this->assertNotSame($healthDisabled->id, $selected->id);
        $this->assertNotSame($blocked->id, $selected->id);
    }

    /** @test */
    public function select_fallback_sim_for_reassignment_bypasses_new_assignment_filters_but_still_excludes_blocked(): void
    {
        $company = $this->createCompany();

        $reassignmentEligible = $this->createSim($company, [
            'accept_new_assignments' => false,
            'disabled_for_new_assignments' => true,
            'operator_status' => 'active',
        ]);

        $blocked = $this->createSim($company, [
            'accept_new_assignments' => true,
            'disabled_for_new_assignments' => false,
            'operator_status' => 'blocked',
        ]);

        $newAssignmentSelection = $this->service->selectFallbackSim($company->id);
        $this->assertNull($newAssignmentSelection);

        $reassignmentSelection = $this->service->selectFallbackSim($company->id, 999999);
        $this->assertNotNull($reassignmentSelection);
        $this->assertSame($reassignmentEligible->id, $reassignmentSelection->id);
        $this->assertNotSame($blocked->id, $reassignmentSelection->id);
    }
}
