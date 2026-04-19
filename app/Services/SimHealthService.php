<?php

namespace App\Services;

use App\Models\Sim;
use App\Models\SimHealthLog;

class SimHealthService
{
    private const STUCK_6H_HOURS = 6;
    private const STUCK_24H_HOURS = 24;
    private const STUCK_3D_HOURS = 72;

    /**
     * @var \App\Services\GatewayHealthPolicyService
     */
    protected $policy;

    /**
     * @param \App\Services\GatewayHealthPolicyService $policy
     */
    public function __construct(GatewayHealthPolicyService $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Evaluate SIM health using last_success_at only.
     *
     * @param \App\Models\Sim $sim
     * @return array<string, mixed>
     */
    public function checkHealth(Sim $sim): array
    {
        $thresholdMinutes = $this->unhealthyThresholdMinutes();
        $minutesSinceLastSuccess = $this->minutesSinceLastSuccess($sim);
        $isUnhealthy = $this->isUnhealthy($sim, $thresholdMinutes);
        $companySimCount = $this->companySimCount((int) $sim->company_id);

        $status = $isUnhealthy ? 'unhealthy' : 'healthy';
        $reason = null;

        if ($isUnhealthy) {
            $reason = $minutesSinceLastSuccess === null
                ? 'no_success_recorded'
                : 'no_success_within_'.$thresholdMinutes.'_minutes';
        }

        $changed = $this->syncAssignmentDisableFlag($sim, $isUnhealthy, $companySimCount);

        return [
            'status' => $status,
            'reason' => $reason,
            'last_success_at' => $sim->last_success_at,
            'minutes_since_last_success' => $minutesSinceLastSuccess,
            'company_sim_count' => $companySimCount,
            'disabled_for_new_assignments' => (bool) $sim->disabled_for_new_assignments,
            'disable_flag_changed' => $changed,
            'stuck' => $this->computeStuckAge($sim),
            'runtime_control' => $this->runtimeControlSnapshot($sim),
        ];
    }

    /**
     * Persist runtime failure signal and apply temporary SIM suppression on repeated failures.
     *
     * @param \App\Models\Sim $sim
     * @param string|null $error
     * @param string|null $errorLayer
     * @param array<string,mixed> $retryDecision
     * @param int $messageId
     * @param string $source
     * @return array<string,mixed>
     */
    public function recordRuntimeFailure(
        Sim $sim,
        ?string $error,
        ?string $errorLayer,
        array $retryDecision,
        int $messageId,
        string $source = 'worker_send_failure'
    ): array {
        $now = now();
        $runtimeFailureWindowMinutes = $this->runtimeFailureWindowMinutes();
        $runtimeFailureThreshold = $this->runtimeFailureThreshold();
        $runtimeSuppressionMinutes = $this->runtimeSuppressionMinutes();
        $payload = [
            'source' => $source,
            'message_id' => $messageId,
            'error' => $error,
            'error_layer' => $errorLayer,
            'retryable' => (bool) ($retryDecision['retryable'] ?? true),
            'classification' => $retryDecision['classification'] ?? 'retryable',
            'classification_reason' => $retryDecision['reason'] ?? null,
        ];

        SimHealthLog::query()->create([
            'sim_id' => $sim->id,
            'status' => 'error',
            'error_message' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'logged_at' => $now,
        ]);

        $windowStartedAt = $now->copy()->subMinutes($runtimeFailureWindowMinutes);
        $recentFailureCount = SimHealthLog::query()
            ->where('sim_id', $sim->id)
            ->where('status', 'error')
            ->where('logged_at', '>=', $windowStartedAt)
            ->count();

        $suppressed = false;
        $suppressedUntil = $sim->cooldown_until;
        $cooldownActivated = false;

        if ($recentFailureCount >= $runtimeFailureThreshold) {
            $candidateUntil = $now->copy()->addMinutes($runtimeSuppressionMinutes);
            $currentCooldown = $sim->cooldown_until;

            if ($currentCooldown === null || $currentCooldown->lessThan($candidateUntil)) {
                $sim->mode = 'COOLDOWN';
                $sim->cooldown_until = $candidateUntil;
                $suppressedUntil = $candidateUntil;
                $cooldownActivated = true;
            } else {
                $suppressedUntil = $currentCooldown;
            }

            $suppressed = true;
        }

        $sim->last_error_at = $now;
        $sim->save();

        if ($cooldownActivated) {
            SimHealthLog::query()->create([
                'sim_id' => $sim->id,
                'status' => 'cooldown',
                'error_message' => json_encode([
                    'source' => $source,
                    'reason' => 'runtime_failure_threshold_reached',
                    'window_minutes' => $runtimeFailureWindowMinutes,
                    'threshold' => $runtimeFailureThreshold,
                    'recent_failure_count' => $recentFailureCount,
                    'suppressed_until' => $suppressedUntil !== null ? $suppressedUntil->toIso8601String() : null,
                ], JSON_UNESCAPED_SLASHES),
                'logged_at' => $now,
            ]);
        }

        return [
            'suppressed' => $suppressed,
            'suppressed_until' => $suppressedUntil !== null ? $suppressedUntil->toIso8601String() : null,
            'recent_failure_count' => $recentFailureCount,
            'window_minutes' => $runtimeFailureWindowMinutes,
            'threshold' => $runtimeFailureThreshold,
            'classification' => $retryDecision['classification'] ?? 'retryable',
            'retryable' => (bool) ($retryDecision['retryable'] ?? true),
            'error' => $error,
            'error_layer' => $errorLayer,
        ];
    }

    /**
     * Determine if SIM is unhealthy from last_success_at threshold only.
     *
     * @param \App\Models\Sim $sim
     * @param int $thresholdMinutes
     * @return bool
     */
    public function isUnhealthy(Sim $sim, ?int $thresholdMinutes = null): bool
    {
        $thresholdMinutes = $thresholdMinutes ?? $this->unhealthyThresholdMinutes();
        $minutesSinceLastSuccess = $this->minutesSinceLastSuccess($sim);

        if ($minutesSinceLastSuccess === null) {
            return true;
        }

        return $minutesSinceLastSuccess >= $thresholdMinutes;
    }

    /**
     * Compute stuck-age warning flags from last_success_at only.
     *
     * If last_success_at is null, all stuck flags are true (explicit no-success history).
     *
     * @param \App\Models\Sim $sim
     * @return array<string, bool>
     */
    public function computeStuckAge(Sim $sim): array
    {
        $minutesSinceLastSuccess = $this->minutesSinceLastSuccess($sim);

        if ($minutesSinceLastSuccess === null) {
            return [
                'stuck_6h' => true,
                'stuck_24h' => true,
                'stuck_3d' => true,
            ];
        }

        $hours = (int) floor($minutesSinceLastSuccess / 60);

        return [
            'stuck_6h' => $hours >= self::STUCK_6H_HOURS,
            'stuck_24h' => $hours >= self::STUCK_24H_HOURS,
            'stuck_3d' => $hours >= self::STUCK_3D_HOURS,
        ];
    }

    /**
     * Get minutes since last success from last_success_at.
     *
     * Returns null when last_success_at is null.
     *
     * @param \App\Models\Sim $sim
     * @return int|null
     */
    public function minutesSinceLastSuccess(Sim $sim): ?int
    {
        if ($sim->last_success_at === null) {
            return null;
        }

        return now()->diffInMinutes($sim->last_success_at);
    }

    /**
     * Apply reversible health-based assignment disable rules.
     *
     * Rule:
     * - unhealthy + company has more than 1 SIM => disable_for_new_assignments = true
     * - healthy + company has more than 1 SIM   => disable_for_new_assignments = false
     * - company has 1 SIM                        => do not auto-toggle
     * - safety guardrail                         => never leave company with zero assignment-enabled SIMs
     *
     * @param \App\Models\Sim $sim
     * @param bool $isUnhealthy
     * @param int $companySimCount
     * @return bool
     */
    protected function syncAssignmentDisableFlag(Sim $sim, bool $isUnhealthy, int $companySimCount): bool
    {
        if ($companySimCount <= 1) {
            return false;
        }

        if ($isUnhealthy && !$sim->disabled_for_new_assignments) {
            if (!$this->hasOtherAssignmentEnabledSim((int) $sim->company_id, (int) $sim->id)) {
                return false;
            }

            $sim->update(['disabled_for_new_assignments' => true]);
            return true;
        }

        if ($isUnhealthy && $sim->disabled_for_new_assignments) {
            // Safety valve: if everything became disabled, re-enable this SIM to avoid no_sim_available lockout.
            if (!$this->hasAnyAssignmentEnabledSim((int) $sim->company_id)) {
                $sim->update(['disabled_for_new_assignments' => false]);
                return true;
            }

            return false;
        }

        if (!$isUnhealthy && $sim->disabled_for_new_assignments) {
            $sim->update(['disabled_for_new_assignments' => false]);
            return true;
        }

        return false;
    }

    /**
     * Get company SIM count for health disable eligibility checks.
     *
     * @param int $companyId
     * @return int
     */
    protected function companySimCount(int $companyId): int
    {
        return Sim::query()
            ->where('company_id', $companyId)
            ->count();
    }

    /**
     * Determine whether company has any SIM currently enabled for new assignment selection.
     *
     * @param int $companyId
     * @return bool
     */
    protected function hasAnyAssignmentEnabledSim(int $companyId): bool
    {
        return Sim::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('operator_status', '!=', 'blocked')
            ->where('accept_new_assignments', true)
            ->where('disabled_for_new_assignments', false)
            ->exists();
    }

    /**
     * Determine whether company has another SIM (excluding current) enabled for assignment.
     *
     * @param int $companyId
     * @param int $excludeSimId
     * @return bool
     */
    protected function hasOtherAssignmentEnabledSim(int $companyId, int $excludeSimId): bool
    {
        return Sim::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $excludeSimId)
            ->where('status', 'active')
            ->where('operator_status', '!=', 'blocked')
            ->where('accept_new_assignments', true)
            ->where('disabled_for_new_assignments', false)
            ->exists();
    }

    /**
     * @param \App\Models\Sim $sim
     * @return array<string,mixed>
     */
    protected function runtimeControlSnapshot(Sim $sim): array
    {
        $runtimeFailureWindowMinutes = $this->runtimeFailureWindowMinutes();
        $runtimeFailureThreshold = $this->runtimeFailureThreshold();
        $windowStartedAt = now()->subMinutes($runtimeFailureWindowMinutes);

        $recentFailureCount = SimHealthLog::query()
            ->where('sim_id', $sim->id)
            ->where('status', 'error')
            ->where('logged_at', '>=', $windowStartedAt)
            ->count();

        $latestFailureLog = SimHealthLog::query()
            ->where('sim_id', $sim->id)
            ->where('status', 'error')
            ->orderByDesc('logged_at')
            ->first();

        $latestPayload = null;
        if ($latestFailureLog !== null && is_string($latestFailureLog->error_message)) {
            $decoded = json_decode($latestFailureLog->error_message, true);
            if (is_array($decoded)) {
                $latestPayload = $decoded;
            }
        }

        $suppressed = $sim->isCoolingDown() && $recentFailureCount >= $runtimeFailureThreshold;

        return [
            'suppressed' => $suppressed,
            'suppressed_until' => $suppressed && $sim->cooldown_until !== null
                ? $sim->cooldown_until->toIso8601String()
                : null,
            'recent_failure_count' => $recentFailureCount,
            'window_minutes' => $runtimeFailureWindowMinutes,
            'threshold' => $runtimeFailureThreshold,
            'last_failure_at' => ($latestFailureLog !== null && $latestFailureLog->logged_at !== null)
                ? $latestFailureLog->logged_at->toIso8601String()
                : null,
            'last_error' => is_array($latestPayload) ? ($latestPayload['error'] ?? null) : null,
            'last_error_layer' => is_array($latestPayload) ? ($latestPayload['error_layer'] ?? null) : null,
            'last_classification' => is_array($latestPayload) ? ($latestPayload['classification'] ?? null) : null,
            'last_retryable' => is_array($latestPayload) && array_key_exists('retryable', $latestPayload)
                ? (bool) $latestPayload['retryable']
                : null,
        ];
    }

    /**
     * @return int
     */
    protected function unhealthyThresholdMinutes(): int
    {
        return $this->policy->getInt('sim_health_unhealthy_threshold_minutes');
    }

    /**
     * @return int
     */
    protected function runtimeFailureWindowMinutes(): int
    {
        return $this->policy->getInt('sim_health_runtime_failure_window_minutes');
    }

    /**
     * @return int
     */
    protected function runtimeFailureThreshold(): int
    {
        return $this->policy->getInt('sim_health_runtime_failure_threshold');
    }

    /**
     * @return int
     */
    protected function runtimeSuppressionMinutes(): int
    {
        return $this->policy->getInt('sim_health_runtime_suppression_minutes');
    }
}
