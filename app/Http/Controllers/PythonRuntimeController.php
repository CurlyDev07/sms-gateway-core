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
            ->orderBy('id')
            ->get([
                'id',
                'phone_number',
                'sim_label',
                'imsi',
                'status',
                'operator_status',
                'accept_new_assignments',
            ]);

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
                'tenant_sims' => $tenantSims->map(function (Sim $sim): array {
                    return [
                        'id' => (int) $sim->id,
                        'phone_number' => $sim->phone_number,
                        'sim_label' => $sim->sim_label,
                        'imsi' => $sim->imsi,
                        'status' => $sim->status,
                        'operator_status' => $sim->operator_status,
                        'accept_new_assignments' => (bool) $sim->accept_new_assignments,
                    ];
                })->values()->all(),
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
     * Manually bind a runtime SIM identifier (IMSI) to a tenant-owned SIM row.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\OperatorAuditLogService $auditLogService
     * @return \Illuminate\Http\JsonResponse
     */
    public function mapSim(Request $request, OperatorAuditLogService $auditLogService): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'runtime_sim_id' => ['required', 'string', 'regex:/^[0-9]{15}$/'],
            'tenant_sim_db_id' => ['required', 'integer', 'min:1'],
        ], [
            'runtime_sim_id.regex' => 'runtime_sim_id_must_be_15_digit_imsi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $runtimeSimId = trim((string) $validated['runtime_sim_id']);
        $tenantSimDbId = (int) $validated['tenant_sim_db_id'];

        $tenantSim = Sim::query()
            ->where('company_id', $companyId)
            ->where('id', $tenantSimDbId)
            ->first();

        if ($tenantSim === null) {
            return response()->json([
                'ok' => false,
                'error' => 'sim_not_found',
            ], 404);
        }

        $sameCompanyConflict = Sim::query()
            ->where('company_id', $companyId)
            ->where('imsi', $runtimeSimId)
            ->where('id', '!=', $tenantSim->id)
            ->exists();

        if ($sameCompanyConflict) {
            return response()->json([
                'ok' => false,
                'error' => 'runtime_sim_id_already_mapped_in_tenant',
            ], 409);
        }

        $otherTenantConflict = Sim::query()
            ->where('imsi', $runtimeSimId)
            ->where('company_id', '!=', $companyId)
            ->exists();

        if ($otherTenantConflict) {
            return response()->json([
                'ok' => false,
                'error' => 'runtime_sim_id_already_mapped_in_other_tenant',
            ], 409);
        }

        $previousImsi = trim((string) ($tenantSim->imsi ?? ''));
        $tenantSim->imsi = $runtimeSimId;
        $tenantSim->save();
        $tenantSim->refresh();

        $auditLogService->record(
            $request,
            'runtime.python_map_sim',
            'sim',
            (int) $tenantSim->id,
            [
                'runtime_sim_id' => $runtimeSimId,
                'previous_imsi' => $previousImsi === '' ? null : $previousImsi,
                'new_imsi' => $runtimeSimId,
            ]
        );

        return response()->json([
            'ok' => true,
            'result' => [
                'tenant_sim_db_id' => (int) $tenantSim->id,
                'runtime_sim_id' => $runtimeSimId,
                'imsi' => (string) $tenantSim->imsi,
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
            'effective_send_ready' => is_bool($modem['effective_send_ready'] ?? null) ? $modem['effective_send_ready'] : null,
            'realtime_probe_ready' => is_bool($modem['realtime_probe_ready'] ?? null) ? $modem['realtime_probe_ready'] : null,
            'send_ready' => is_bool($modem['send_ready'] ?? null) ? $modem['send_ready'] : null,
            'identifier_source' => $this->firstString($modem, ['identifier_source']),
            'identifier_source_confidence' => $this->firstString($modem, ['identifier_source_confidence']),
            'consecutive_probe_failures' => $this->extractNullableInt($modem, ['consecutive_probe_failures']),
            'last_good_probe_at' => $this->firstString($modem, ['last_good_probe_at']),
            'readiness_reason_code' => $this->firstString($modem, ['readiness_reason_code']),
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

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $keys
     * @return int|null
     */
    protected function extractNullableInt(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            if (is_int($data[$key])) {
                return $data[$key];
            }

            if (is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return null;
    }
}
