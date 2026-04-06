<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Sim;
use App\Services\QueueRebuildService;
use App\Services\SimOperatorStatusService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class SimAdminController extends Controller
{
    /**
     * Update operator status for a tenant-owned SIM.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @param \App\Services\SimOperatorStatusService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function setStatus(
        Request $request,
        int $id,
        SimOperatorStatusService $service
    ): JsonResponse {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'operator_status' => ['required', 'string', 'in:active,paused,blocked'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'     => false,
                'error'  => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $sim = Sim::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($sim === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'sim_not_found',
            ], 404);
        }

        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);

        try {
            $service->setOperatorStatus($sim, $validator->validated()['operator_status'], $company);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        $sim->refresh();

        return response()->json([
            'ok'  => true,
            'sim' => $this->simPayload($sim),
        ]);
    }

    /**
     * Enable a tenant-owned SIM for new customer assignments.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function enableAssignments(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $sim = Sim::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($sim === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'sim_not_found',
            ], 404);
        }

        $sim->update(['accept_new_assignments' => true]);

        Log::info('SIM enabled for new assignments via API', [
            'sim_id'     => $sim->id,
            'company_id' => $sim->company_id,
        ]);

        $sim->refresh();

        return response()->json([
            'ok'  => true,
            'sim' => $this->simPayload($sim),
        ]);
    }

    /**
     * Disable a tenant-owned SIM for new customer assignments.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function disableAssignments(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $sim = Sim::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($sim === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'sim_not_found',
            ], 404);
        }

        $sim->update(['accept_new_assignments' => false]);

        Log::info('SIM disabled for new assignments via API', [
            'sim_id'     => $sim->id,
            'company_id' => $sim->company_id,
        ]);

        $sim->refresh();

        return response()->json([
            'ok'  => true,
            'sim' => $this->simPayload($sim),
        ]);
    }

    /**
     * Trigger a Redis queue rebuild for a tenant-owned SIM from DB pending truth.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @param \App\Services\QueueRebuildService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function rebuildQueue(Request $request, int $id, QueueRebuildService $service): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        try {
            $result = $service->rebuildSimQueue($companyId, $id);
        } catch (RuntimeException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok'     => true,
            'result' => $result,
        ]);
    }

    /**
     * Build the standard SIM response payload.
     *
     * @param \App\Models\Sim $sim
     * @return array<string, mixed>
     */
    private function simPayload(Sim $sim): array
    {
        return [
            'id'                           => $sim->id,
            'company_id'                   => $sim->company_id,
            'operator_status'              => $sim->operator_status,
            'status'                       => $sim->status,
            'accept_new_assignments'       => (bool) $sim->accept_new_assignments,
            'disabled_for_new_assignments' => (bool) $sim->disabled_for_new_assignments,
        ];
    }
}
