<?php

namespace Tests\Unit\Services;

use App\Models\OutboundMessage;
use App\Services\SimSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        Cache::flush();
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

    /** @test */
    public function select_fallback_sim_for_new_assignment_can_admit_auto_disabled_or_cooldown_sim(): void
    {
        $company = $this->createCompany();

        $autoDisabledCooldown = $this->createSim($company, [
            'accept_new_assignments' => true,
            'disabled_for_new_assignments' => true,
            'cooldown_until' => now()->addMinutes(10),
            'operator_status' => 'active',
        ]);

        $available = $this->service->selectAvailableSim($company->id);
        $this->assertNull($available);

        $fallback = $this->service->selectFallbackSim($company->id);
        $this->assertNotNull($fallback);
        $this->assertSame($autoDisabledCooldown->id, $fallback->id);
    }

    /** @test */
    public function select_available_sim_skips_candidate_under_hysteresis_hold_for_new_assignment(): void
    {
        $company = $this->createCompany();

        $held = $this->createSim($company, [
            'last_sent_at' => now()->subMinutes(10),
        ]);

        $next = $this->createSim($company, [
            'last_sent_at' => now()->subMinutes(5),
        ]);

        Cache::put('sim-selection:hold:sim:'.$held->id, '1', now()->addMinutes(5));

        $selected = $this->service->selectAvailableSim($company->id);

        $this->assertNotNull($selected);
        $this->assertSame($next->id, $selected->id);
    }

    /** @test */
    public function select_available_sim_keeps_service_alive_when_all_candidates_are_under_hold(): void
    {
        $company = $this->createCompany();

        $simA = $this->createSim($company, [
            'last_sent_at' => now()->subMinutes(10),
        ]);

        $simB = $this->createSim($company, [
            'last_sent_at' => now()->subMinutes(8),
        ]);

        Cache::put('sim-selection:hold:sim:'.$simA->id, '1', now()->addMinutes(5));
        Cache::put('sim-selection:hold:sim:'.$simB->id, '1', now()->addMinutes(5));

        $selected = $this->service->selectAvailableSim($company->id);

        $this->assertNotNull($selected);
        $this->assertSame($simA->id, $selected->id);
    }

    /** @test */
    public function high_recent_failure_sim_is_temporarily_held_and_next_candidate_is_selected(): void
    {
        config()->set('services.gateway.sim_selection_failure_hold_threshold', 3);
        config()->set('services.gateway.sim_selection_failure_window_minutes', 15);
        config()->set('services.gateway.sim_selection_queue_hold_threshold', 9999);

        $company = $this->createCompany();

        $flaky = $this->createSim($company, [
            'last_sent_at' => now()->subMinutes(10),
        ]);

        $healthy = $this->createSim($company, [
            'last_sent_at' => now()->subMinutes(5),
        ]);

        foreach (range(1, 3) as $i) {
            OutboundMessage::query()->create([
                'company_id' => $company->id,
                'sim_id' => $flaky->id,
                'customer_phone' => '0917000100'.$i,
                'message' => 'recent fail '.$i,
                'message_type' => 'CHAT',
                'priority' => 100,
                'status' => 'failed',
                'failure_reason' => 'AT_NOT_RESPONDING',
            ]);
        }

        $selected = $this->service->selectAvailableSim($company->id);

        $this->assertNotNull($selected);
        $this->assertSame($healthy->id, $selected->id);
        $this->assertTrue(Cache::has('sim-selection:hold:sim:'.$flaky->id));
    }
}
