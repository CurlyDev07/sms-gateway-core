<?php

namespace App\Services;

use App\Models\Sim;
use Carbon\Carbon;

class SimSelectionService
{
    /**
     * Select the best SIM for a company using strict availability rules.
     *
     * @param int $companyId
     * @param int|null $excludeSimId
     * @return \App\Models\Sim|null
     */
    public function selectAvailableSim(int $companyId, ?int $excludeSimId = null): ?Sim
    {
        $today = Carbon::today()->toDateString();

        $query = Sim::query()
            ->select('sims.*')
            ->withCount([
                'outboundMessages as current_load' => function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)
                        ->whereIn('status', ['pending', 'queued', 'sending']);
                },
            ])
            ->leftJoin('sim_daily_stats', function ($join) use ($today) {
                $join->on('sim_daily_stats.sim_id', '=', 'sims.id')
                    ->where('sim_daily_stats.stat_date', '=', $today);
            })
            ->where('sims.company_id', $companyId)
            ->where('sims.status', 'active')
            ->where('sims.operator_status', '!=', 'blocked')
            ->where(function ($q) {
                $q->whereNull('sims.cooldown_until')
                    ->orWhere('sims.cooldown_until', '<=', now());
            })
            ->whereRaw('COALESCE(sim_daily_stats.sent_count, 0) < sims.daily_limit');

        // excludeSimId === null means new customer assignment (not reassignment/failover).
        // Reassignment paths pass the current SIM's ID and are exempt from new-assignment filters.
        if ($excludeSimId === null) {
            $query->where('sims.accept_new_assignments', true)
                ->where('sims.disabled_for_new_assignments', false);
        }

        if ($excludeSimId !== null) {
            $query->where('sims.id', '!=', $excludeSimId);
        }

        return $query
            ->orderBy('current_load')
            ->orderBy('sims.last_sent_at')
            ->orderBy('sims.id')
            ->first();
    }

    /**
     * Select a fallback SIM when strict selection has no result.
     *
     * @param int $companyId
     * @param int|null $excludeSimId
     * @return \App\Models\Sim|null
     */
    public function selectFallbackSim(int $companyId, ?int $excludeSimId = null): ?Sim
    {
        $query = Sim::query()
            ->withCount([
                'outboundMessages as current_load' => function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)
                        ->whereIn('status', ['pending', 'queued', 'sending']);
                },
            ])
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('operator_status', '!=', 'blocked')
            ->where(function ($q) {
                $q->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', now());
            });

        // excludeSimId === null means new customer assignment (not reassignment/failover).
        // Reassignment paths pass the current SIM's ID and are exempt from new-assignment filters.
        if ($excludeSimId === null) {
            $query->where('accept_new_assignments', true)
                ->where('disabled_for_new_assignments', false);
        }

        if ($excludeSimId !== null) {
            $query->where('id', '!=', $excludeSimId);
        }

        return $query
            ->orderBy('current_load')
            ->orderBy('last_sent_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Select best SIM with fallback behavior.
     *
     * @param int $companyId
     * @param int|null $excludeSimId
     * @return \App\Models\Sim|null
     */
    public function selectBestSim(int $companyId, ?int $excludeSimId = null): ?Sim
    {
        $sim = $this->selectAvailableSim($companyId, $excludeSimId);

        if ($sim !== null) {
            return $sim;
        }

        return $this->selectFallbackSim($companyId, $excludeSimId);
    }
}
