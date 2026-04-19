<?php

namespace App\Services;

use App\Models\CustomerSimAssignment;
use App\Models\Sim;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
        $customerPhone = $this->normalizeCustomerPhone($customerPhone);

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
     * Automatic reassignment is disabled in Phase 1 (manual migration only).
     *
     * @param int $companyId
     * @param string $customerPhone
     * @return \App\Models\Sim|null
     */
    public function reassignSim(int $companyId, string $customerPhone): ?Sim
    {
        $customerPhone = $this->normalizeCustomerPhone($customerPhone);

        Log::warning('Automatic SIM reassignment blocked (manual migration only)', [
            'company_id' => $companyId,
            'customer_phone' => $customerPhone,
        ]);

        throw new RuntimeException('Automatic SIM reassignment is disabled. Use manual migration commands instead.');
    }

    /**
     * Mark assignment as customer has replied.
     *
     * @param int $companyId
     * @param string $customerPhone
     * @return \App\Models\CustomerSimAssignment|null
     */
    public function markReplied(int $companyId, string $customerPhone, ?int $simId = null): ?CustomerSimAssignment
    {
        $customerPhone = $this->normalizeCustomerPhone($customerPhone);

        return DB::transaction(function () use ($companyId, $customerPhone, $simId) {
            $assignment = CustomerSimAssignment::query()
                ->where('company_id', $companyId)
                ->where('customer_phone', $customerPhone)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                if ($simId === null) {
                    return null;
                }

                $assignment = CustomerSimAssignment::query()->create([
                    'company_id' => $companyId,
                    'customer_phone' => $customerPhone,
                    'sim_id' => $simId,
                    'status' => 'active',
                    'assigned_at' => now(),
                    'last_used_at' => now(),
                    'last_inbound_at' => now(),
                    'has_replied' => true,
                ]);

                Log::info('SIM assignment created from inbound message', [
                    'company_id' => $companyId,
                    'customer_phone' => $customerPhone,
                    'sim_id' => $simId,
                    'assignment_id' => $assignment->id,
                ]);

                return $assignment;
            }

            $updates = [
                'has_replied' => true,
                'last_inbound_at' => now(),
                'last_used_at' => now(),
            ];

            if (
                $simId !== null
                && (int) $assignment->sim_id !== $simId
                && !(bool) $assignment->migration_locked
            ) {
                $updates['sim_id'] = $simId;
                $updates['assigned_at'] = now();
                $updates['status'] = 'active';
            }

            $assignment->update($updates);

            return $assignment;
        });
    }

    /**
     * Normalize customer phone into local 09 format where possible.
     *
     * @param string $customerPhone
     * @return string
     */
    protected function normalizeCustomerPhone(string $customerPhone): string
    {
        $customerPhone = preg_replace('/\s+/', '', trim($customerPhone)) ?? '';

        if (str_starts_with($customerPhone, '+63') && strlen($customerPhone) === 13) {
            return '0'.substr($customerPhone, 3);
        }

        if (str_starts_with($customerPhone, '63') && strlen($customerPhone) === 12) {
            return '0'.substr($customerPhone, 2);
        }

        return $customerPhone;
    }
}
