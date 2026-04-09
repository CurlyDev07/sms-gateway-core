<?php

namespace App\Http\Controllers;

use App\Services\OperatorAuditLogService;
use App\Services\SimMigrationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class MigrationController extends Controller
{
    /**
     * Migrate a single customer's assignment and eligible messages to a new SIM.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\SimMigrationService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function migrateSingleCustomer(
        Request $request,
        SimMigrationService $service,
        OperatorAuditLogService $auditLogService
    ): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'from_sim_id'    => ['required', 'integer', 'min:1'],
            'to_sim_id'      => ['required', 'integer', 'min:1'],
            'customer_phone' => ['required', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'error'   => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            $result = $service->migrateSingleCustomer(
                $companyId,
                (int) $validated['from_sim_id'],
                (int) $validated['to_sim_id'],
                (string) $validated['customer_phone']
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        $auditLogService->record(
            $request,
            'migration.single',
            'sim',
            (int) $validated['from_sim_id'],
            [
                'from_sim_id' => (int) $validated['from_sim_id'],
                'to_sim_id' => (int) $validated['to_sim_id'],
                'customer_phone' => (string) $validated['customer_phone'],
                'result' => $result,
            ]
        );

        return response()->json([
            'ok'     => true,
            'result' => $result,
        ]);
    }

    /**
     * Bulk migrate all assignments and eligible messages from one SIM to another.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\SimMigrationService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function migrateBulk(
        Request $request,
        SimMigrationService $service,
        OperatorAuditLogService $auditLogService
    ): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'from_sim_id' => ['required', 'integer', 'min:1'],
            'to_sim_id'   => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'error'   => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            $result = $service->migrateBulk(
                $companyId,
                (int) $validated['from_sim_id'],
                (int) $validated['to_sim_id']
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        $auditLogService->record(
            $request,
            'migration.bulk',
            'sim',
            (int) $validated['from_sim_id'],
            [
                'from_sim_id' => (int) $validated['from_sim_id'],
                'to_sim_id' => (int) $validated['to_sim_id'],
                'result' => $result,
            ]
        );

        return response()->json([
            'ok'     => true,
            'result' => $result,
        ]);
    }

    /**
     * Rebalance safe-to-migrate sticky assignments from one tenant SIM to another.
     *
     * Conservative behavior:
     * - requires explicit source/destination SIM IDs
     * - only moves assignment rows eligible for migration
     * - only moves pending/queued outbound rows for moved customers
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\SimMigrationService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function rebalance(
        Request $request,
        SimMigrationService $service,
        OperatorAuditLogService $auditLogService
    ): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'from_sim_id' => ['required', 'integer', 'min:1'],
            'to_sim_id'   => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'error'   => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            $result = $service->rebalanceSafeAssignments(
                $companyId,
                (int) $validated['from_sim_id'],
                (int) $validated['to_sim_id']
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        $auditLogService->record(
            $request,
            'migration.rebalance',
            'sim',
            (int) $validated['from_sim_id'],
            [
                'from_sim_id' => (int) $validated['from_sim_id'],
                'to_sim_id' => (int) $validated['to_sim_id'],
                'result' => $result,
            ]
        );

        return response()->json([
            'ok'     => true,
            'result' => $result,
        ]);
    }
}
