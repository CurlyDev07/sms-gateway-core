<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sim;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SimOperatorStatusService
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_STATUSES = [
        'active',
        'paused',
        'blocked',
    ];

    /**
     * Set operator status for a SIM.
     *
     * Phase 0 behavior:
     * - validate status
     * - update DB field only
     * - audit log status change
     *
     * @param \App\Models\Sim $sim
     * @param string $status
     * @param \App\Models\Company $company
     * @return void
     */
    public function setOperatorStatus(Sim $sim, string $status, Company $company): void
    {
        $normalizedStatus = strtolower(trim($status));

        if (!$this->validateStatus($normalizedStatus)) {
            throw new InvalidArgumentException('Invalid operator status: '.$status);
        }

        if ((int) $sim->company_id !== (int) $company->id) {
            throw new InvalidArgumentException('SIM does not belong to authenticated company');
        }

        $currentStatus = (string) $sim->operator_status;

        if ($currentStatus === $normalizedStatus) {
            return;
        }

        $sim->update([
            'operator_status' => $normalizedStatus,
        ]);

        Log::info('SIM operator status changed', [
            'sim_id' => $sim->id,
            'company_id' => $sim->company_id,
            'old_status' => $currentStatus,
            'new_status' => $normalizedStatus,
        ]);
    }

    /**
     * Set operator status to paused.
     *
     * @param \App\Models\Sim $sim
     * @param \App\Models\Company $company
     * @return void
     */
    public function pauseSim(Sim $sim, Company $company): void
    {
        $this->setOperatorStatus($sim, 'paused', $company);
    }

    /**
     * Set operator status to blocked.
     *
     * @param \App\Models\Sim $sim
     * @param \App\Models\Company $company
     * @return void
     */
    public function blockSim(Sim $sim, Company $company): void
    {
        $this->setOperatorStatus($sim, 'blocked', $company);
    }

    /**
     * Set operator status to active.
     *
     * @param \App\Models\Sim $sim
     * @param \App\Models\Company $company
     * @return void
     */
    public function activateSim(Sim $sim, Company $company): void
    {
        $this->setOperatorStatus($sim, 'active', $company);
    }

    /**
     * Validate operator status value.
     *
     * @param string $status
     * @return bool
     */
    public function validateStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::ALLOWED_STATUSES, true);
    }
}
