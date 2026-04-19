<?php

namespace App\Services;

use App\Models\OutboundMessage;
use App\Models\Sim;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        $candidates = $query
            ->orderBy('current_load')
            ->orderBy('sims.last_sent_at')
            ->orderBy('sims.id')
            ->get();

        return $this->pickCandidate($candidates, $companyId, $excludeSimId === null);
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

        $candidates = $query
            ->orderBy('current_load')
            ->orderBy('last_sent_at')
            ->orderBy('id')
            ->get();

        return $this->pickCandidate($candidates, $companyId, $excludeSimId === null);
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

    /**
     * Pick one SIM from ordered candidates, applying soft hold/hysteresis only for new assignments.
     *
     * @param \Illuminate\Support\Collection<int,\App\Models\Sim> $candidates
     * @param int $companyId
     * @param bool $newAssignment
     * @return \App\Models\Sim|null
     */
    protected function pickCandidate(Collection $candidates, int $companyId, bool $newAssignment): ?Sim
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        if (!$newAssignment) {
            return $candidates->first();
        }

        $simIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $recentFailureCounts = $this->recentFailureCountsBySim($companyId, $simIds);

        foreach ($candidates as $candidate) {
            $simId = (int) $candidate->id;

            if ($this->isHoldActive($simId)) {
                continue;
            }

            $recentFailures = (int) ($recentFailureCounts[$simId] ?? 0);

            if ($this->shouldDeprioritize($candidate, $recentFailures)) {
                $this->activateHold($candidate, $recentFailures);
                continue;
            }

            return $candidate;
        }

        // Safety valve: if all candidates are held/deprioritized, still pick the best-ranked one.
        return $candidates->first();
    }

    /**
     * @param int $companyId
     * @param array<int,int> $simIds
     * @return array<int,int>
     */
    protected function recentFailureCountsBySim(int $companyId, array $simIds): array
    {
        if ($simIds === []) {
            return [];
        }

        $windowMinutes = max(1, (int) config('services.gateway.sim_selection_failure_window_minutes', 15));
        $windowStart = now()->subMinutes($windowMinutes);

        return OutboundMessage::query()
            ->where('company_id', $companyId)
            ->whereIn('sim_id', $simIds)
            ->where('status', 'failed')
            ->where('updated_at', '>=', $windowStart)
            ->groupBy('sim_id')
            ->selectRaw('sim_id, COUNT(*) as aggregate_count')
            ->pluck('aggregate_count', 'sim_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param \App\Models\Sim $sim
     * @param int $recentFailures
     * @return bool
     */
    protected function shouldDeprioritize(Sim $sim, int $recentFailures): bool
    {
        $queueThreshold = max(1, (int) config('services.gateway.sim_selection_queue_hold_threshold', 100));
        $failureThreshold = max(1, (int) config('services.gateway.sim_selection_failure_hold_threshold', 3));
        $currentLoad = (int) ($sim->current_load ?? 0);

        return $currentLoad >= $queueThreshold || $recentFailures >= $failureThreshold;
    }

    /**
     * @param \App\Models\Sim $sim
     * @param int $recentFailures
     * @return void
     */
    protected function activateHold(Sim $sim, int $recentFailures): void
    {
        $simId = (int) $sim->id;
        $currentLoad = (int) ($sim->current_load ?? 0);
        $holdSeconds = max(30, (int) config('services.gateway.sim_selection_hysteresis_hold_seconds', 300));
        $key = $this->holdCacheKey($simId);

        if (!Cache::has($key)) {
            Log::info('SIM assignment hold activated', [
                'sim_id' => $simId,
                'hold_seconds' => $holdSeconds,
                'current_load' => $currentLoad,
                'recent_failure_count' => $recentFailures,
            ]);
        }

        Cache::put($key, now()->addSeconds($holdSeconds)->toIso8601String(), now()->addSeconds($holdSeconds));
    }

    /**
     * @param int $simId
     * @return bool
     */
    protected function isHoldActive(int $simId): bool
    {
        return Cache::has($this->holdCacheKey($simId));
    }

    /**
     * @param int $simId
     * @return string
     */
    protected function holdCacheKey(int $simId): string
    {
        return 'sim-selection:hold:sim:'.$simId;
    }
}
