<?php

namespace App\Services;

use App\Models\Sim;
use Illuminate\Support\Facades\Log;

class RuntimeSimSyncService
{
    /**
     * @var \App\Services\PythonRuntimeClient
     */
    protected $runtimeClient;

    /**
     * @param \App\Services\PythonRuntimeClient $runtimeClient
     */
    public function __construct(PythonRuntimeClient $runtimeClient)
    {
        $this->runtimeClient = $runtimeClient;
    }

    /**
     * Sync SIM assignment-disable flags against live runtime readiness by IMSI.
     *
     * Rule:
     * - mapped IMSI present + send-ready in runtime => disable_for_new_assignments = false
     * - mapped IMSI missing or not send-ready       => disable_for_new_assignments = true
     * - guardrail: never disable the last assignment-enabled SIM in a company
     *
     * @param int|null $companyId
     * @return array<string,mixed>
     */
    public function sync(?int $companyId = null): array
    {
        $discover = $this->runtimeClient->discover();

        if (($discover['ok'] ?? false) !== true) {
            Log::warning('Runtime SIM sync skipped: discovery failed', [
                'company_id_filter' => $companyId,
                'error' => $discover['error'] ?? null,
                'status' => $discover['status'] ?? null,
            ]);

            return [
                'ok' => false,
                'error' => $discover['error'] ?? 'runtime_discovery_failed',
                'status' => $discover['status'] ?? null,
                'company_id_filter' => $companyId,
                'runtime_modems_total' => 0,
                'runtime_imsi_total' => 0,
                'runtime_ready_imsi_total' => 0,
                'sims_scanned' => 0,
                'sims_enabled' => 0,
                'sims_disabled' => 0,
                'guardrail_skipped' => 0,
                'ineligible_skipped' => 0,
            ];
        }

        $runtimeByImsi = $this->indexRuntimeByImsi($discover['modems'] ?? []);

        $query = Sim::query()
            ->whereNotNull('imsi')
            ->orderBy('id');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $scanned = 0;
        $enabled = 0;
        $disabled = 0;
        $guardrailSkipped = 0;
        $ineligibleSkipped = 0;

        $query->chunkById(200, function ($sims) use (
            &$scanned,
            &$enabled,
            &$disabled,
            &$guardrailSkipped,
            &$ineligibleSkipped,
            $runtimeByImsi
        ) {
            foreach ($sims as $sim) {
                $scanned++;

                if (!$this->isAssignmentToggleEligible($sim)) {
                    $ineligibleSkipped++;
                    continue;
                }

                $imsi = trim((string) $sim->imsi);
                $runtimeReady = isset($runtimeByImsi[$imsi]) && $runtimeByImsi[$imsi]['ready'] === true;

                if ($runtimeReady && $sim->disabled_for_new_assignments) {
                    $sim->update(['disabled_for_new_assignments' => false]);
                    $enabled++;
                    continue;
                }

                if (!$runtimeReady && !$sim->disabled_for_new_assignments) {
                    if (!$this->hasOtherAssignmentEnabledSim((int) $sim->company_id, (int) $sim->id)) {
                        $guardrailSkipped++;
                        continue;
                    }

                    $sim->update(['disabled_for_new_assignments' => true]);
                    $disabled++;
                }
            }
        });

        $runtimeReadyImsiTotal = 0;
        foreach ($runtimeByImsi as $row) {
            if (($row['ready'] ?? false) === true) {
                $runtimeReadyImsiTotal++;
            }
        }

        $summary = [
            'ok' => true,
            'error' => null,
            'status' => $discover['status'] ?? null,
            'company_id_filter' => $companyId,
            'runtime_modems_total' => count($discover['modems'] ?? []),
            'runtime_imsi_total' => count($runtimeByImsi),
            'runtime_ready_imsi_total' => $runtimeReadyImsiTotal,
            'sims_scanned' => $scanned,
            'sims_enabled' => $enabled,
            'sims_disabled' => $disabled,
            'guardrail_skipped' => $guardrailSkipped,
            'ineligible_skipped' => $ineligibleSkipped,
        ];

        Log::info('Runtime SIM sync completed', $summary);

        return $summary;
    }

    /**
     * @param array<int,mixed> $modems
     * @return array<string,array{ready:bool}>
     */
    protected function indexRuntimeByImsi(array $modems): array
    {
        $index = [];

        foreach ($modems as $modem) {
            if (!is_array($modem)) {
                continue;
            }

            $imsi = $this->extractImsi($modem);

            if ($imsi === null) {
                continue;
            }

            $ready = $this->runtimeSendReady($modem);

            if (!isset($index[$imsi])) {
                $index[$imsi] = ['ready' => $ready];
                continue;
            }

            // Prefer "ready=true" when multiple modem rows resolve to same IMSI.
            if ($ready) {
                $index[$imsi]['ready'] = true;
            }
        }

        return $index;
    }

    /**
     * @param array<string,mixed> $modem
     * @return string|null
     */
    protected function extractImsi(array $modem): ?string
    {
        $candidate = null;

        if (isset($modem['sim_id']) && is_scalar($modem['sim_id'])) {
            $candidate = trim((string) $modem['sim_id']);
        } elseif (isset($modem['imsi']) && is_scalar($modem['imsi'])) {
            $candidate = trim((string) $modem['imsi']);
        }

        if ($candidate === null || $candidate === '') {
            return null;
        }

        if (!preg_match('/^[0-9]{15}$/', $candidate)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param array<string,mixed> $modem
     * @return bool
     */
    protected function runtimeSendReady(array $modem): bool
    {
        if (is_bool($modem['effective_send_ready'] ?? null)) {
            return (bool) $modem['effective_send_ready'];
        }

        if (is_bool($modem['realtime_probe_ready'] ?? null)) {
            return (bool) $modem['realtime_probe_ready'];
        }

        return (bool) ($modem['at_ok'] ?? false)
            && (bool) ($modem['sim_ready'] ?? false)
            && (bool) ($modem['creg_registered'] ?? false);
    }

    /**
     * @param \App\Models\Sim $sim
     * @return bool
     */
    protected function isAssignmentToggleEligible(Sim $sim): bool
    {
        if (!$sim->isActive()) {
            return false;
        }

        if (!$sim->accept_new_assignments) {
            return false;
        }

        if ($sim->isOperatorBlocked() || $sim->isOperatorPaused()) {
            return false;
        }

        return true;
    }

    /**
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
            ->where('operator_status', 'active')
            ->where('accept_new_assignments', true)
            ->where('disabled_for_new_assignments', false)
            ->exists();
    }
}

