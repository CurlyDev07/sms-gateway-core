<?php

namespace App\Services;

use App\Models\Sim;

class SimHealthService
{
    private const UNHEALTHY_THRESHOLD_MINUTES = 30;
    private const STUCK_6H_HOURS = 6;
    private const STUCK_24H_HOURS = 24;
    private const STUCK_3D_HOURS = 72;

    /**
     * Evaluate SIM health using last_success_at only.
     *
     * @param \App\Models\Sim $sim
     * @return array<string, mixed>
     */
    public function checkHealth(Sim $sim): array
    {
        $minutesSinceLastSuccess = $this->minutesSinceLastSuccess($sim);
        $isUnhealthy = $this->isUnhealthy($sim, self::UNHEALTHY_THRESHOLD_MINUTES);
        $companySimCount = $this->companySimCount((int) $sim->company_id);

        $status = $isUnhealthy ? 'unhealthy' : 'healthy';
        $reason = null;

        if ($isUnhealthy) {
            $reason = $minutesSinceLastSuccess === null
                ? 'no_success_recorded'
                : 'no_success_within_30_minutes';
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
        ];
    }

    /**
     * Determine if SIM is unhealthy from last_success_at threshold only.
     *
     * @param \App\Models\Sim $sim
     * @param int $thresholdMinutes
     * @return bool
     */
    public function isUnhealthy(Sim $sim, int $thresholdMinutes = self::UNHEALTHY_THRESHOLD_MINUTES): bool
    {
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
            $sim->update(['disabled_for_new_assignments' => true]);
            return true;
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
}

