<?php

namespace App\Http\Controllers;

use App\Models\Sim;
use App\Services\RedisQueueService;
use App\Services\SimHealthService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimController extends Controller
{
    /**
     * List all SIMs for the authenticated tenant with health and queue depth.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\SimHealthService $simHealthService
     * @param \App\Services\RedisQueueService $redisQueueService
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(
        Request $request,
        SimHealthService $simHealthService,
        RedisQueueService $redisQueueService
    ): JsonResponse {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $sims = Sim::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        $data = $sims->map(function (Sim $sim) use ($simHealthService, $redisQueueService) {
            $health = $simHealthService->checkHealth($sim);
            $simId = (int) $sim->id;

            return [
                'id'                          => $sim->id,
                'uuid'                        => $sim->uuid,
                'phone_number'                => $sim->phone_number,
                'carrier'                     => $sim->carrier,
                'sim_label'                   => $sim->sim_label,
                'status'                      => $sim->status,
                'operator_status'             => $sim->operator_status,
                'accept_new_assignments'      => (bool) $sim->accept_new_assignments,
                'disabled_for_new_assignments' => (bool) $sim->disabled_for_new_assignments,
                'last_success_at'             => $sim->last_success_at !== null ? $sim->last_success_at->toIso8601String() : null,
                'health' => [
                    'status'                  => $health['status'],
                    'reason'                  => $health['reason'],
                    'minutes_since_last_success' => $health['minutes_since_last_success'],
                    'runtime_control'         => $health['runtime_control'] ?? [],
                ],
                'stuck' => $health['stuck'],
                'queue_depth' => [
                    'total'    => $redisQueueService->depth($simId),
                    'chat'     => $redisQueueService->depth($simId, 'chat'),
                    'followup' => $redisQueueService->depth($simId, 'followup'),
                    'blasting' => $redisQueueService->depth($simId, 'blasting'),
                ],
            ];
        });

        return response()->json([
            'ok'   => true,
            'sims' => $data,
        ]);
    }
}
