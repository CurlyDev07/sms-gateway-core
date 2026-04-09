<?php

namespace App\Http\Controllers;

use App\Models\Sim;
use App\Services\PythonRuntimeClient;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PythonRuntimeController extends Controller
{
    /**
     * Show Python runtime health and modem discovery snapshot.
     *
     * Discovery rows are tenant-filtered by IMSI/sim_id match to tenant SIM records.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\PythonRuntimeClient $runtimeClient
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, PythonRuntimeClient $runtimeClient): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $health = $runtimeClient->health();
        $discovery = $runtimeClient->discover();

        $tenantImsis = Sim::query()
            ->where('company_id', $companyId)
            ->whereNotNull('imsi')
            ->pluck('imsi')
            ->map(function ($imsi): string {
                return trim((string) $imsi);
            })
            ->filter()
            ->values()
            ->all();

        $tenantImsiSet = array_fill_keys($tenantImsis, true);

        $visibleModems = [];
        foreach ($discovery['modems'] as $modem) {
            $mappedSimId = $this->extractModemSimIdentifier($modem);

            if ($mappedSimId === null || !isset($tenantImsiSet[$mappedSimId])) {
                continue;
            }

            $visibleModems[] = $this->normalizeModemRow($modem);
        }

        return response()->json([
            'ok' => $health['ok'] && $discovery['ok'],
            'health' => [
                'ok' => $health['ok'],
                'status' => $health['status'],
                'error' => $health['error'],
                'data' => $health['data'],
            ],
            'discovery' => [
                'ok' => $discovery['ok'],
                'status' => $discovery['status'],
                'error' => $discovery['error'],
                'discovered_total' => count($discovery['modems']),
                'tenant_visible_total' => count($visibleModems),
                'modems' => $visibleModems,
                'tenant_imsi_mapped' => count($tenantImsis),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $modem
     * @return array<string,mixed>
     */
    protected function normalizeModemRow(array $modem): array
    {
        return [
            'device_id' => $this->firstString($modem, ['device_id', 'modem_id', 'id']),
            'sim_id' => $this->extractModemSimIdentifier($modem),
            'port' => $this->firstString($modem, ['port', 'device_port', 'tty']),
            'at_ok' => $modem['at_ok'] ?? null,
            'sim_ready' => $modem['sim_ready'] ?? null,
            'creg_registered' => $modem['creg_registered'] ?? null,
            'signal' => $modem['signal'] ?? null,
            'last_seen_at' => $this->firstString($modem, ['last_seen_at', 'last_seen']),
        ];
    }

    /**
     * @param array<string,mixed> $modem
     * @return string|null
     */
    protected function extractModemSimIdentifier(array $modem): ?string
    {
        $value = $this->firstString($modem, ['sim_id', 'imsi']);

        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $keys
     * @return string|null
     */
    protected function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            if (is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return null;
    }
}
