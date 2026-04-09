<?php

namespace App\Http\Controllers;

use App\Models\CustomerSimAssignment;
use App\Models\Sim;
use App\Services\OperatorAuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AssignmentController extends Controller
{
    /**
     * List customer-SIM assignments for the authenticated tenant.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $query = CustomerSimAssignment::query()
            ->with('sim')
            ->where('company_id', $companyId);

        $customerPhone = $request->query('customer_phone');
        if ($customerPhone !== null && $customerPhone !== '') {
            $query->where('customer_phone', $customerPhone);
        }

        $simId = $request->query('sim_id');
        if ($simId !== null && $simId !== '') {
            $query->where('sim_id', (int) $simId);
        }

        $assignments = $query->orderBy('id')->get();

        $data = $assignments->map(function (CustomerSimAssignment $assignment) {
            $sim = $assignment->sim;

            return [
                'id'             => $assignment->id,
                'customer_phone' => $assignment->customer_phone,
                'sim_id'         => $assignment->sim_id,
                'status'         => $assignment->status,
                'has_replied'    => (bool) $assignment->has_replied,
                'safe_to_migrate' => (bool) $assignment->safe_to_migrate,
                'assigned_at'    => $assignment->assigned_at !== null ? $assignment->assigned_at->toIso8601String() : null,
                'last_used_at'   => $assignment->last_used_at !== null ? $assignment->last_used_at->toIso8601String() : null,
                'last_inbound_at' => $assignment->last_inbound_at !== null ? $assignment->last_inbound_at->toIso8601String() : null,
                'last_outbound_at' => $assignment->last_outbound_at !== null ? $assignment->last_outbound_at->toIso8601String() : null,
                'created_at'     => $assignment->created_at !== null ? $assignment->created_at->toIso8601String() : null,
                'updated_at'     => $assignment->updated_at !== null ? $assignment->updated_at->toIso8601String() : null,
                'sim' => [
                    'id'              => $sim->id,
                    'phone_number'    => $sim->phone_number,
                    'operator_status' => $sim->operator_status,
                    'status'          => $sim->status,
                ],
            ];
        });

        return response()->json([
            'ok'          => true,
            'assignments' => $data,
        ]);
    }

    /**
     * Force-set a customer's SIM assignment for the authenticated tenant.
     *
     * Creates the assignment if none exists; updates the SIM if one does.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function set(Request $request, OperatorAuditLogService $auditLogService): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'customer_phone' => ['required', 'string', 'max:30'],
            'sim_id'         => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'error'   => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $simId = (int) $validated['sim_id'];
        $customerPhone = trim((string) $validated['customer_phone']);

        $sim = Sim::query()
            ->where('company_id', $companyId)
            ->where('id', $simId)
            ->first();

        if ($sim === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'sim_not_found',
            ], 404);
        }

        $assignment = CustomerSimAssignment::query()
            ->updateOrCreate(
                [
                    'company_id'     => $companyId,
                    'customer_phone' => $customerPhone,
                ],
                [
                    'sim_id'      => $simId,
                    'status'      => 'active',
                    'assigned_at' => now(),
                    'last_used_at' => now(),
                ]
            );
        $wasCreated = (bool) $assignment->wasRecentlyCreated;

        Log::info('Customer SIM assignment set via API', [
            'company_id'     => $companyId,
            'customer_phone' => $customerPhone,
            'sim_id'         => $simId,
            'assignment_id'  => $assignment->id,
            'was_recent'     => !$assignment->wasRecentlyCreated,
        ]);

        $assignment->refresh();

        $auditLogService->record(
            $request,
            'assignment.set',
            'customer_sim_assignment',
            (int) $assignment->id,
            [
                'customer_phone' => $customerPhone,
                'sim_id' => $simId,
                'created' => $wasCreated,
            ]
        );

        return response()->json([
            'ok'         => true,
            'assignment' => $this->assignmentPayload($assignment),
        ]);
    }

    /**
     * Mark a customer's assignment as safe to migrate for the authenticated tenant.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markSafe(Request $request, OperatorAuditLogService $auditLogService): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'customer_phone' => ['required', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'error'   => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $customerPhone = trim((string) $validator->validated()['customer_phone']);

        $assignment = CustomerSimAssignment::query()
            ->where('company_id', $companyId)
            ->where('customer_phone', $customerPhone)
            ->first();

        if ($assignment === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'assignment_not_found',
            ], 404);
        }

        $assignment->update(['safe_to_migrate' => true]);

        Log::info('Assignment marked safe to migrate via API', [
            'company_id'     => $companyId,
            'customer_phone' => $customerPhone,
            'assignment_id'  => $assignment->id,
            'sim_id'         => $assignment->sim_id,
        ]);

        $assignment->refresh();

        $auditLogService->record(
            $request,
            'assignment.mark_safe',
            'customer_sim_assignment',
            (int) $assignment->id,
            [
                'customer_phone' => $customerPhone,
                'sim_id' => (int) $assignment->sim_id,
                'safe_to_migrate' => (bool) $assignment->safe_to_migrate,
            ]
        );

        return response()->json([
            'ok'         => true,
            'assignment' => $this->assignmentPayload($assignment),
        ]);
    }

    /**
     * Build the standard assignment response payload.
     *
     * @param \App\Models\CustomerSimAssignment $assignment
     * @return array<string, mixed>
     */
    private function assignmentPayload(CustomerSimAssignment $assignment): array
    {
        return [
            'id'              => $assignment->id,
            'company_id'      => $assignment->company_id,
            'customer_phone'  => $assignment->customer_phone,
            'sim_id'          => $assignment->sim_id,
            'has_replied'     => (bool) $assignment->has_replied,
            'safe_to_migrate' => (bool) $assignment->safe_to_migrate,
            'created_at'      => $assignment->created_at !== null ? $assignment->created_at->toIso8601String() : null,
            'updated_at'      => $assignment->updated_at !== null ? $assignment->updated_at->toIso8601String() : null,
        ];
    }
}
