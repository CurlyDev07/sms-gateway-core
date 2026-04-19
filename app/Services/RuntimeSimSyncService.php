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
     * Sync SIM assignment-disable flags against watchdog health send-ready state by SIM identity.
     *
     * Rule:
     * - mapped SIM identity present + send_ready=true  => disable_for_new_assignments = false
     * - mapped SIM identity missing or send_ready=false => disable_for_new_assignments = true
     * - guardrail: never disable the last assignment-enabled SIM in a company
     *
     * Identity resolution order per tenant SIM row:
     * - IMSI (preferred)
     * - slot_name
     * - modem_id
     *
     * @param int|null $companyId
     * @return array<string,mixed>
     */
    public function sync(?int $companyId = null): array
    {
        $health = $this->runtimeClient->health();

        if (($health['ok'] ?? false) !== true) {
            Log::warning('Runtime SIM sync skipped: health failed', [
                'company_id_filter' => $companyId,
                'error' => $health['error'] ?? null,
                'status' => $health['status'] ?? null,
            ]);

            return [
                'ok' => false,
                'error' => $health['error'] ?? 'runtime_health_failed',
                'status' => $health['status'] ?? null,
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

        $runtimeIndex = $this->indexRuntimeIdentities($health['modems'] ?? []);
        $runtimeByImsi = $runtimeIndex['by_imsi'];
        $runtimeByAlias = $runtimeIndex['by_alias'];

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
            $runtimeByImsi,
            $runtimeByAlias
        ) {
            foreach ($sims as $sim) {
                $scanned++;

                if (!$this->isAssignmentToggleEligible($sim)) {
                    $ineligibleSkipped++;
                    continue;
                }

                $runtimeReady = $this->resolveRuntimeReadyForSim($sim, $runtimeByImsi, $runtimeByAlias);

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
            'status' => $health['status'] ?? null,
            'company_id_filter' => $companyId,
            'runtime_modems_total' => count($health['modems'] ?? []),
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
     * Build runtime readiness indexes from discovery rows.
     *
     * @param array<int,mixed> $modems
     * @return array{by_imsi:array<string,array{ready:bool}>,by_alias:array<string,array{ready:bool}>}
     */
    protected function indexRuntimeIdentities(array $modems): array
    {
        $byImsi = [];
        $byAlias = [];

        foreach ($modems as $modem) {
            if (!is_array($modem)) {
                continue;
            }

            $ready = $this->runtimeSendReady($modem);
            $imsi = $this->extractImsi($modem);

            if ($imsi !== null) {
                if (!isset($byImsi[$imsi])) {
                    $byImsi[$imsi] = ['ready' => $ready];
                } elseif ($ready) {
                    $byImsi[$imsi]['ready'] = true;
                }
            }

            foreach ($this->extractRuntimeAliases($modem) as $alias) {
                if (!isset($byAlias[$alias])) {
                    $byAlias[$alias] = ['ready' => $ready];
                } elseif ($ready) {
                    $byAlias[$alias]['ready'] = true;
                }
            }
        }

        return [
            'by_imsi' => $byImsi,
            'by_alias' => $byAlias,
        ];
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
     * Extract non-IMSI runtime aliases that may map to SIM slot_name/modem_id.
     *
     * @param array<string,mixed> $modem
     * @return array<int,string>
     */
    protected function extractRuntimeAliases(array $modem): array
    {
        $aliases = [];
        $keys = ['sim_id', 'device_id', 'modem_id', 'id', 'port'];

        foreach ($keys as $key) {
            if (!isset($modem[$key]) || !is_scalar($modem[$key])) {
                continue;
            }

            $normalized = $this->normalizeAlias((string) $modem[$key]);

            if ($normalized === null) {
                continue;
            }

            if (preg_match('/^[0-9]{15}$/', $normalized)) {
                // IMSI keys are handled by dedicated IMSI index.
                continue;
            }

            $aliases[$normalized] = $normalized;
        }

        return array_values($aliases);
    }

    /**
     * Resolve runtime send-ready state for a tenant SIM row.
     *
     * @param \App\Models\Sim $sim
     * @param array<string,array{ready:bool}> $runtimeByImsi
     * @param array<string,array{ready:bool}> $runtimeByAlias
     * @return bool
     */
    protected function resolveRuntimeReadyForSim(Sim $sim, array $runtimeByImsi, array $runtimeByAlias): bool
    {
        $imsi = trim((string) $sim->imsi);

        if ($imsi !== '' && isset($runtimeByImsi[$imsi])) {
            return $runtimeByImsi[$imsi]['ready'] === true;
        }

        $slotAlias = $this->normalizeAlias((string) $sim->slot_name);
        if ($slotAlias !== null && isset($runtimeByAlias[$slotAlias])) {
            return $runtimeByAlias[$slotAlias]['ready'] === true;
        }

        $modemAlias = $this->normalizeAlias((string) $sim->modem_id);
        if ($modemAlias !== null && isset($runtimeByAlias[$modemAlias])) {
            return $runtimeByAlias[$modemAlias]['ready'] === true;
        }

        return false;
    }

    /**
     * @param string $value
     * @return string|null
     */
    protected function normalizeAlias(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return strtolower($value);
    }

    /**
     * @param array<string,mixed> $modem
     * @return bool
     */
    protected function runtimeSendReady(array $modem): bool
    {
        if (is_bool($modem['send_ready'] ?? null)) {
            return (bool) $modem['send_ready'];
        }

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
