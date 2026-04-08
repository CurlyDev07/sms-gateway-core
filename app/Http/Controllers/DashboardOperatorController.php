<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DashboardOperatorController extends Controller
{
    /**
     * List tenant-local dashboard operators.
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

        /** @var \App\Models\User|null $actor */
        $actor = $request->user();

        $operators = User::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'company_id', 'operator_role']);

        return response()->json([
            'ok' => true,
            'operators' => $operators->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'company_id' => $user->company_id,
                    'operator_role' => $user->operator_role,
                ];
            })->values(),
            'meta' => [
                'current_user_id' => $actor !== null ? $actor->id : null,
                'current_user_role' => $actor !== null ? $actor->operator_role : null,
                'can_manage_roles' => $actor !== null && (string) $actor->operator_role === User::ROLE_OWNER,
            ],
        ]);
    }

    /**
     * Update a tenant-local operator role.
     *
     * Owner-only endpoint.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'operator_role' => ['required', 'string', 'in:owner,admin,support'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        /** @var \App\Models\User|null $actor */
        $actor = $request->user();

        if ($actor === null || (string) $actor->operator_role !== User::ROLE_OWNER) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
                'message' => 'insufficient_operator_role',
            ], 403);
        }

        $target = User::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($target === null) {
            return response()->json([
                'ok' => false,
                'error' => 'operator_not_found',
            ], 404);
        }

        if ((int) $actor->id === (int) $target->id) {
            return response()->json([
                'ok' => false,
                'error' => 'cannot_change_own_role',
            ], 422);
        }

        $newRole = (string) $validator->validated()['operator_role'];
        $oldRole = (string) $target->operator_role;

        if ($oldRole === User::ROLE_OWNER && $newRole !== User::ROLE_OWNER) {
            $ownerCount = User::query()
                ->where('company_id', $companyId)
                ->where('operator_role', User::ROLE_OWNER)
                ->count();

            if ($ownerCount <= 1) {
                return response()->json([
                    'ok' => false,
                    'error' => 'last_owner_required',
                ], 422);
            }
        }

        if ($oldRole !== $newRole) {
            $target->update([
                'operator_role' => $newRole,
            ]);
            $target->refresh();
        }

        return response()->json([
            'ok' => true,
            'operator' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'company_id' => $target->company_id,
                'operator_role' => $target->operator_role,
            ],
            'no_change' => $oldRole === $newRole,
        ]);
    }
}
