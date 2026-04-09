<?php

namespace App\Http\Controllers;

use App\Models\OperatorAuditLog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DashboardAuditLogController extends Controller
{
    /**
     * List tenant-local dashboard operator audit logs.
     *
     * Read-only endpoint.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->query(), [
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $limit = (int) ($validator->validated()['limit'] ?? 100);

        $logs = OperatorAuditLog::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'ok' => true,
            'logs' => $logs->map(function (OperatorAuditLog $log) {
                return [
                    'id' => $log->id,
                    'company_id' => $log->company_id,
                    'actor_user_id' => $log->actor_user_id,
                    'action' => $log->action,
                    'target_type' => $log->target_type,
                    'target_id' => $log->target_id,
                    'metadata' => $log->metadata ?? [],
                    'created_at' => $log->created_at !== null ? $log->created_at->toIso8601String() : null,
                ];
            })->values(),
        ]);
    }
}
