<?php

namespace Tests\Unit\Services;

use App\Models\SimHealthLog;
use App\Services\SimHealthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimHealthServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private SimHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SimHealthService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function unhealthy_sim_with_multiple_company_sims_is_auto_disabled_for_new_assignments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $target = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(31),
            'disabled_for_new_assignments' => false,
        ]);
        $this->createSim($company);

        $result = $this->service->checkHealth($target);

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame('no_success_within_30_minutes', $result['reason']);
        $this->assertTrue($target->fresh()->disabled_for_new_assignments);
        $this->assertTrue($result['disable_flag_changed']);
    }

    /** @test */
    public function unhealthy_single_sim_company_is_not_auto_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $target = $this->createSim($company, [
            'last_success_at' => null,
            'disabled_for_new_assignments' => false,
        ]);

        $result = $this->service->checkHealth($target);

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame('no_success_recorded', $result['reason']);
        $this->assertFalse($target->fresh()->disabled_for_new_assignments);
        $this->assertFalse($result['disable_flag_changed']);
    }

    /** @test */
    public function recovered_sim_is_auto_re_enabled_for_new_assignments_when_company_has_multiple_sims(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $target = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(5),
            'disabled_for_new_assignments' => true,
        ]);
        $this->createSim($company);

        $result = $this->service->checkHealth($target);

        $this->assertSame('healthy', $result['status']);
        $this->assertNull($result['reason']);
        $this->assertFalse($target->fresh()->disabled_for_new_assignments);
        $this->assertTrue($result['disable_flag_changed']);
    }

    /** @test */
    public function unhealthy_multi_sim_company_never_disables_all_sims_for_new_assignments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $simA = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(60),
            'disabled_for_new_assignments' => false,
        ]);
        $simB = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(60),
            'disabled_for_new_assignments' => false,
        ]);

        $this->service->checkHealth($simA);
        $this->service->checkHealth($simB);

        $enabledCount = $company->sims()
            ->where('accept_new_assignments', true)
            ->where('disabled_for_new_assignments', false)
            ->count();

        $this->assertGreaterThanOrEqual(1, $enabledCount);
    }

    /** @test */
    public function unhealthy_multi_sim_company_auto_recovers_when_all_sims_are_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $simA = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(60),
            'disabled_for_new_assignments' => true,
        ]);
        $simB = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(60),
            'disabled_for_new_assignments' => true,
        ]);

        $this->service->checkHealth($simA);
        $this->service->checkHealth($simB);

        $enabledCount = $company->sims()
            ->where('accept_new_assignments', true)
            ->where('disabled_for_new_assignments', false)
            ->count();

        $this->assertGreaterThanOrEqual(1, $enabledCount);
    }

    /** @test */
    public function compute_stuck_age_returns_expected_threshold_flags(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-30 12:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subHours(25),
        ]);

        $stuck = $this->service->computeStuckAge($sim);

        $this->assertTrue($stuck['stuck_6h']);
        $this->assertTrue($stuck['stuck_24h']);
        $this->assertFalse($stuck['stuck_3d']);
    }

    /** @test */
    public function compute_stuck_age_returns_all_true_when_last_success_at_is_null(): void
    {
        // When last_success_at is null (never had a successful send), all stuck flags are true.
        // This was the universal production state before the last_success_at bug was fixed.
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['last_success_at' => null]);

        $stuck = $this->service->computeStuckAge($sim);

        $this->assertTrue($stuck['stuck_6h']);
        $this->assertTrue($stuck['stuck_24h']);
        $this->assertTrue($stuck['stuck_3d']);
    }

    /** @test */
    public function check_health_returns_correct_full_shape_for_healthy_sim_with_recent_last_success_at(): void
    {
        // Validates the healthy code path returns the expected result shape now that
        // last_success_at is correctly populated by SimStateService on every successful send.
        Carbon::setTestNow(Carbon::parse('2026-04-06 10:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(10),
            'disabled_for_new_assignments' => false,
        ]);

        $result = $this->service->checkHealth($sim);

        $this->assertSame('healthy', $result['status']);
        $this->assertNull($result['reason']);
        $this->assertNotNull($result['last_success_at']);
        $this->assertIsInt($result['minutes_since_last_success']);
        $this->assertSame(10, $result['minutes_since_last_success']);
        $this->assertFalse($result['stuck']['stuck_6h']);
        $this->assertFalse($result['stuck']['stuck_24h']);
        $this->assertFalse($result['stuck']['stuck_3d']);
        $this->assertFalse($result['disable_flag_changed']);
    }

    /** @test */
    public function is_unhealthy_returns_true_at_exactly_30_minute_boundary(): void
    {
        // isUnhealthy uses >= threshold, so exactly 30 minutes must be unhealthy.
        Carbon::setTestNow(Carbon::parse('2026-04-06 10:30:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(30),
        ]);

        $this->assertTrue($this->service->isUnhealthy($sim));
    }

    /** @test */
    public function repeated_runtime_failures_trigger_temporary_runtime_suppression_cooldown(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10 10:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'mode' => 'NORMAL',
            'cooldown_until' => null,
        ]);

        $retryDecision = [
            'retryable' => true,
            'classification' => 'retryable',
            'reason' => 'layer_retryable_by_policy',
        ];

        $this->service->recordRuntimeFailure($sim, 'RUNTIME_TIMEOUT', 'transport', $retryDecision, 1001, 'worker_send_failure');
        $this->service->recordRuntimeFailure($sim, 'RUNTIME_TIMEOUT', 'transport', $retryDecision, 1002, 'worker_send_failure');
        $outcome = $this->service->recordRuntimeFailure($sim, 'RUNTIME_TIMEOUT', 'transport', $retryDecision, 1003, 'worker_send_failure');

        $freshSim = $sim->fresh();

        $this->assertTrue($outcome['suppressed']);
        $this->assertSame(3, $outcome['recent_failure_count']);
        $this->assertSame('COOLDOWN', $freshSim->mode);
        $this->assertNotNull($freshSim->cooldown_until);
        $this->assertTrue($freshSim->cooldown_until->greaterThan(now()));

        $this->assertGreaterThanOrEqual(
            3,
            SimHealthLog::query()->where('sim_id', $sim->id)->where('status', 'error')->count()
        );
    }

    /** @test */
    public function check_health_includes_runtime_control_snapshot_fields(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10 11:00:00'));

        $company = $this->createCompany();
        $sim = $this->createSim($company, [
            'last_success_at' => Carbon::now()->subMinutes(8),
            'mode' => 'COOLDOWN',
            'cooldown_until' => Carbon::now()->addMinutes(10),
        ]);

        SimHealthLog::query()->create([
            'sim_id' => $sim->id,
            'status' => 'error',
            'error_message' => json_encode([
                'error' => 'RUNTIME_TIMEOUT',
                'error_layer' => 'transport',
                'classification' => 'retryable',
                'retryable' => true,
            ]),
            'logged_at' => now()->subMinutes(3),
        ]);

        SimHealthLog::query()->create([
            'sim_id' => $sim->id,
            'status' => 'error',
            'error_message' => json_encode([
                'error' => 'RUNTIME_TIMEOUT',
                'error_layer' => 'transport',
                'classification' => 'retryable',
                'retryable' => true,
            ]),
            'logged_at' => now()->subMinutes(2),
        ]);

        SimHealthLog::query()->create([
            'sim_id' => $sim->id,
            'status' => 'error',
            'error_message' => json_encode([
                'error' => 'INVALID_RESPONSE',
                'error_layer' => 'python_api',
                'classification' => 'non_retryable',
                'retryable' => false,
            ]),
            'logged_at' => now()->subMinute(),
        ]);

        $result = $this->service->checkHealth($sim);
        $runtime = $result['runtime_control'];

        $this->assertTrue($runtime['suppressed']);
        $this->assertNotNull($runtime['suppressed_until']);
        $this->assertSame('INVALID_RESPONSE', $runtime['last_error']);
        $this->assertSame('python_api', $runtime['last_error_layer']);
        $this->assertSame('non_retryable', $runtime['last_classification']);
        $this->assertFalse($runtime['last_retryable']);
        $this->assertIsInt($runtime['recent_failure_count']);
    }
}
