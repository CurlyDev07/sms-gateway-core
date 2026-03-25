<?php

namespace App\Services;

use App\Models\CustomerSimAssignment;
use App\Models\Sim;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerSimAssignmentService
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
     * Assign or reuse a SIM for a customer under a company.
     *
     * @param int $companyId
     * @param string $customerPhone
     * @return \App\Models\Sim|null
     */
    public function assignSim(int $companyId, string $customerPhone): ?Sim
    {
        $customerPhone = trim($customerPhone);

        return DB::transaction(function () use ($companyId, $customerPhone) {
            $lockedAssignment = CustomerSimAssignment::query()
                ->with('sim')
                ->where('company_id', $companyId)
                ->where('customer_phone', $customerPhone)
                ->lockForUpdate()
                ->first();

            // Re-check inside the same transaction after acquiring row lock.
            $assignment = CustomerSimAssignment::query()
                ->with('sim')
                ->where('company_id', $companyId)
                ->where('customer_phone', $customerPhone)
                ->first();

            if ($assignment === null) {
                $assignment = $lockedAssignment;
            }

            if ($assignment !== null && $assignment->isActive() && $assignment->sim !== null && $assignment->sim->isAvailable()) {
                $assignment->update([
                    'last_used_at' => now(),
                    'last_outbound_at' => now(),
                ]);

                return $assignment->sim;
            }

            $sim = $this->simSelectionService->selectBestSim($companyId);

            if ($sim === null) {
                Log::warning('No SIM available for assignment', [
                    'company_id' => $companyId,
                    'customer_phone' => $customerPhone,
                ]);

                return null;
            }

            if ($assignment === null) {
                try {
                    $assignment = CustomerSimAssignment::create([
                        'company_id' => $companyId,
                        'customer_phone' => $customerPhone,
                        'sim_id' => $sim->id,
                        'status' => 'active',
                        'assigned_at' => now(),
                        'last_used_at' => now(),
                        'last_outbound_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    // Handle duplicate insert caused by concurrent assignment creation.
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }

                    $existingAssignment = CustomerSimAssignment::query()
                        ->with('sim')
                        ->where('company_id', $companyId)
                        ->where('customer_phone', $customerPhone)
                        ->lockForUpdate()
                        ->first();

                    if ($existingAssignment !== null && $existingAssignment->sim !== null) {
                        return $existingAssignment->sim;
                    }

                    throw $e;
                }

                Log::info('SIM assigned to customer', [
                    'company_id' => $companyId,
                    'customer_phone' => $customerPhone,
                    'sim_id' => $sim->id,
                    'assignment_id' => $assignment->id,
                ]);

                return $sim;
            }

            $assignment->update([
                'sim_id' => $sim->id,
                'status' => 'active',
                'assigned_at' => now(),
                'last_used_at' => now(),
                'last_outbound_at' => now(),
            ]);

            Log::info('SIM assignment re-activated for customer', [
                'company_id' => $companyId,
                'customer_phone' => $customerPhone,
                'sim_id' => $sim->id,
                'assignment_id' => $assignment->id,
            ]);

            return $sim;
        });
    }

    /**
     * Reassign a customer to another SIM.
     *
     * Allowed only when safe_to_migrate is true or current SIM is unavailable.
     *
     * @param int $companyId
     * @param string $customerPhone
     * @return \App\Models\Sim|null
     */
    public function reassignSim(int $companyId, string $customerPhone): ?Sim
    {
        $customerPhone = trim($customerPhone);

        return DB::transaction(function () use ($companyId, $customerPhone) {
            $assignment = CustomerSimAssignment::query()
                ->with('sim')
                ->where('company_id', $companyId)
                ->where('customer_phone', $customerPhone)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                return $this->assignSim($companyId, $customerPhone);
            }

            $currentSimUnavailable = $assignment->sim === null || !$assignment->sim->isAvailable();

            if (!$assignment->canMigrate() && !$currentSimUnavailable) {
                Log::info('SIM reassignment skipped: migration not allowed', [
                    'company_id' => $companyId,
                    'customer_phone' => $customerPhone,
                    'assignment_id' => $assignment->id,
                    'sim_id' => $assignment->sim_id,
                ]);

                return $assignment->sim;
            }

            $newSim = $this->simSelectionService->selectBestSim($companyId, $assignment->sim_id);

            if ($newSim === null) {
                Log::warning('SIM reassignment failed: no alternate SIM available', [
                    'company_id' => $companyId,
                    'customer_phone' => $customerPhone,
                    'assignment_id' => $assignment->id,
                    'current_sim_id' => $assignment->sim_id,
                ]);

                return null;
            }

            $previousSimId = $assignment->sim_id;

            $assignment->update([
                'sim_id' => $newSim->id,
                'status' => 'migrated',
                'last_used_at' => now(),
                'last_outbound_at' => now(),
            ]);

            Log::info('SIM reassigned for customer', [
                'company_id' => $companyId,
                'customer_phone' => $customerPhone,
                'assignment_id' => $assignment->id,
                'from_sim_id' => $previousSimId,
                'to_sim_id' => $newSim->id,
            ]);

            return $newSim;
        });
    }

    /**
     * Mark assignment as customer has replied.
     *
     * @param int $companyId
     * @param string $customerPhone
     * @return \App\Models\CustomerSimAssignment|null
     */
    public function markReplied(int $companyId, string $customerPhone): ?CustomerSimAssignment
    {
        $customerPhone = trim($customerPhone);

        $assignment = CustomerSimAssignment::query()
            ->where('company_id', $companyId)
            ->where('customer_phone', $customerPhone)
            ->first();

        if ($assignment === null) {
            return null;
        }

        $assignment->update([
            'has_replied' => true,
            'last_inbound_at' => now(),
            'last_used_at' => now(),
        ]);

        return $assignment;
    }
}
