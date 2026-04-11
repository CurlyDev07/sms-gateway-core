<?php

namespace App\Http\Controllers;

use App\Models\Sim;
use App\Services\OperatorAuditLogService;
use App\Services\PythonRuntimeClient;
use App\Services\PythonRuntimeSendExecutionService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

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

        $tenantSims = Sim::query()
            ->where('company_id', $companyId)
            ->whereNotNull('imsi')
            ->orderBy('id')
            ->get(['id', 'imsi']);

        $tenantImsiToSimId = [];
        foreach ($tenantSims as $tenantSim) {
            $imsi = trim((string) $tenantSim->imsi);

            if ($imsi === '' || isset($tenantImsiToSimId[$imsi])) {
                continue;
            }

            $tenantImsiToSimId[$imsi] = (int) $tenantSim->id;
        }

        $tenantImsis = collect(array_keys($tenantImsiToSimId))
            ->map(function (string $imsi): string {
                return trim($imsi);
            })
            ->filter()
            ->values()
            ->all();

        $tenantImsiSet = array_fill_keys($tenantImsis, true);

        $allModems = [];
        $visibleModems = [];
        foreach ($discovery['modems'] as $modem) {
            $normalized = $this->normalizeModemRow($modem);
            $allModems[] = $normalized;
            $mappedSimId = $normalized['sim_id'];
            $tenantSimDbId = null;

            if ($mappedSimId !== null && isset($tenantImsiToSimId[$mappedSimId])) {
                $tenantSimDbId = (int) $tenantImsiToSimId[$mappedSimId];
            }

            $normalized['tenant_sim_db_id'] = $tenantSimDbId;
            $allModems[count($allModems) - 1] = $normalized;

            if ($mappedSimId === null || !isset($tenantImsiSet[$mappedSimId])) {
                continue;
            }

            $visibleModems[] = $normalized;
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
                'all_modems' => $allModems,
                'tenant_imsi_mapped' => count($tenantImsis),
            ],
        ]);
    }

    /**
     * Execute a direct runtime send-test for one tenant-owned SIM.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\PythonRuntimeSendExecutionService $executionService
     * @param \App\Services\OperatorAuditLogService $auditLogService
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendTest(
        Request $request,
        PythonRuntimeSendExecutionService $executionService,
        OperatorAuditLogService $auditLogService
    ): JsonResponse {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'sim_id' => ['required', 'integer', 'min:1'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string'],
            'client_message_id' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            $result = $executionService->executeForTenant(
                (int) $companyId,
                (int) $validated['sim_id'],
                trim((string) $validated['customer_phone']),
                (string) $validated['message'],
                isset($validated['client_message_id']) ? (string) $validated['client_message_id'] : null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'runtime_send_test_exception',
                'details' => [
                    'exception' => $e->getMessage(),
                ],
            ], 500);
        }

        $auditLogService->record(
            $request,
            'runtime.python_send_test',
            'sim',
            (int) $result['sim_id'],
            [
                'message_id' => (int) $result['message_id'],
                'status' => (string) $result['status'],
                'success' => (bool) $result['success'],
                'error' => $result['error'],
                'error_layer' => $result['error_layer'],
            ]
        );

        if ($result['success'] === true) {
            return response()->json([
                'ok' => true,
                'result' => $result,
            ], 200);
        }

        return response()->json([
            'ok' => false,
            'error' => 'runtime_send_failed',
            'result' => $result,
        ], 502);
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
            'send_ready' => is_bool($modem['send_ready'] ?? null) ? $modem['send_ready'] : null,
            'identifier_source' => $this->firstString($modem, ['identifier_source']),
            'signal' => $modem['signal'] ?? null,
            'probe_error' => $this->firstString($modem, ['probe_error']),
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
