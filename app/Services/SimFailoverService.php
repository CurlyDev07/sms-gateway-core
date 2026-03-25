<?php

namespace App\Services;

use App\Models\CustomerSimAssignment;
use App\Models\OutboundMessage;
use App\Models\Sim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimFailoverService
{
    /**
     * @var \App\Services\SimSelectionService
     */
    protected $simSelectionService;

    /**
     * @param \App\Services\SimSelectionService $simSelectionService
     */
    public function __construct(SimSelectionService $simSelectionService)
    {
        $this->simSelectionService = $simSelectionService;
    }

    /**
     * Determine if SIM is unavailable for failover.
     *
     * @param \App\Models\Sim $sim
     * @return bool
     */
    public function isSimUnavailable(Sim $sim): bool
    {
        if ($sim->status !== 'active') {
            return true;
        }

        return !$sim->isAvailable();
    }

    /**
     * Find replacement SIM in the same company.
     *
     * @param int $companyId
     * @param int|null $excludeSimId
     * @return \App\Models\Sim|null
     */
    public function findReplacementSim(int $companyId, ?int $excludeSimId = null): ?Sim
    {
        return $this->simSelectionService->selectAvailableSim($companyId, $excludeSimId);
    }

    /**
     * Reassign safe pending outbound messages from failed SIM.
     *
     * @param \App\Models\Sim $failedSim
     * @param \App\Models\Sim $replacementSim
     * @return int
     */
    public function failoverPendingMessages(Sim $failedSim, Sim $replacementSim): int
    {
        return DB::transaction(function () use ($failedSim, $replacementSim) {
            $messages = OutboundMessage::query()
                ->where('company_id', $failedSim->company_id)
                ->where('sim_id', $failedSim->id)
                ->where('status', 'pending')
                ->whereNull('locked_at')
                ->lockForUpdate()
                ->get();

            $moved = 0;

            foreach ($messages as $message) {
                // Re-check state under lock before reassignment.
                if ($message->status !== 'pending') {
                    continue;
                }

                $message->sim_id = $replacementSim->id;
                $message->save();
                $moved++;
            }

            return $moved;
        });
    }

    /**
     * Reassign customer sticky assignments from failed SIM.
     *
     * @param \App\Models\Sim $failedSim
     * @param \App\Models\Sim $replacementSim
     * @return int
     */
    public function failoverCustomerAssignments(Sim $failedSim, Sim $replacementSim): int
    {
        $updated = DB::transaction(function () use ($failedSim, $replacementSim) {
            $query = CustomerSimAssignment::query()
                ->where('company_id', $failedSim->company_id)
                ->where('sim_id', $failedSim->id)
                ->where('migration_locked', false);

            if (!$this->isSimUnavailable($failedSim)) {
                $query->where('safe_to_migrate', true);
            }

            $assignments = $query->lockForUpdate()->get();
            $count = 0;

            foreach ($assignments as $assignment) {
                // Preserve active/disabled states; only mark migrated when transition semantics apply.
                $nextStatus = $assignment->status;

                if (!in_array($assignment->status, ['active', 'disabled'], true)) {
                    $nextStatus = 'migrated';
                }

                $assignment->update([
                    'sim_id' => $replacementSim->id,
                    'status' => $nextStatus,
                    'last_used_at' => now(),
                ]);

                $count++;
            }

            return $count;
        });

        $lockedSkipped = CustomerSimAssignment::query()
            ->where('company_id', $failedSim->company_id)
            ->where('sim_id', $failedSim->id)
            ->where('migration_locked', true)
            ->count();

        if ($lockedSkipped > 0) {
            Log::info('Failover skipped assignments due to migration lock', [
                'failed_sim_id' => $failedSim->id,
                'locked_skipped' => $lockedSkipped,
            ]);
        }

        return $updated;
    }

    /**
     * Execute failover for one failed SIM.
     *
     * @param \App\Models\Sim $failedSim
     * @return array<string, mixed>
     */
    public function failoverSim(Sim $failedSim): array
    {
        Log::warning('SIM failover started', [
            'failed_sim_id' => $failedSim->id,
            'company_id' => $failedSim->company_id,
            'status' => $failedSim->status,
        ]);

        if (!$this->isSimUnavailable($failedSim)) {
            return [
                'failed_sim_id' => $failedSim->id,
                'replacement_sim_id' => null,
                'messages_moved' => 0,
                'assignments_moved' => 0,
                'deferred' => true,
                'reason' => 'sim_still_available',
            ];
        }

        $replacementSim = $this->findReplacementSim((int) $failedSim->company_id, (int) $failedSim->id);

        if ($replacementSim === null) {
            Log::warning('SIM failover deferred: no replacement SIM available', [
                'failed_sim_id' => $failedSim->id,
                'company_id' => $failedSim->company_id,
            ]);

            return [
                'failed_sim_id' => $failedSim->id,
                'replacement_sim_id' => null,
                'messages_moved' => 0,
                'assignments_moved' => 0,
                'deferred' => true,
                'reason' => 'no_replacement_sim',
            ];
        }

        Log::info('SIM failover replacement selected', [
            'failed_sim_id' => $failedSim->id,
            'replacement_sim_id' => $replacementSim->id,
            'company_id' => $failedSim->company_id,
        ]);

        return DB::transaction(function () use ($failedSim, $replacementSim) {
            $messagesMoved = $this->failoverPendingMessages($failedSim, $replacementSim);
            $assignmentsMoved = $this->failoverCustomerAssignments($failedSim, $replacementSim);

            Log::info('SIM failover completed', [
                'failed_sim_id' => $failedSim->id,
                'replacement_sim_id' => $replacementSim->id,
                'messages_moved' => $messagesMoved,
                'assignments_moved' => $assignmentsMoved,
            ]);

            return [
                'failed_sim_id' => $failedSim->id,
                'replacement_sim_id' => $replacementSim->id,
                'messages_moved' => $messagesMoved,
                'assignments_moved' => $assignmentsMoved,
                'deferred' => false,
                'reason' => null,
            ];
        });
    }
}
