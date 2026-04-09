<?php

namespace App\Http\Controllers;

use App\Models\OperatorAuditLog;
use App\Support\TenantContext;
use Carbon\Carbon;
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
            'search' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:120'],
            'actor_user_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');

            if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
                $validator->errors()->add('date_to', 'date_to must be on or after date_from.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 100);

        $query = OperatorAuditLog::query()
            ->where('company_id', $companyId);

        if (isset($validated['search']) && trim((string) $validated['search']) !== '') {
            $search = trim((string) $validated['search']);
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('action', 'like', '%'.$search.'%')
                    ->orWhere('target_type', 'like', '%'.$search.'%');
            });
        }

        if (isset($validated['action']) && $validated['action'] !== '') {
            $query->where('action', (string) $validated['action']);
        }

        if (isset($validated['actor_user_id'])) {
            $query->where('actor_user_id', (int) $validated['actor_user_id']);
        }

        if (isset($validated['date_from'])) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', (string) $validated['date_from'])->startOfDay());
        }

        if (isset($validated['date_to'])) {
            $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', (string) $validated['date_to'])->endOfDay());
        }

        $logs = $query
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
