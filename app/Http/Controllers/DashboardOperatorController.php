<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OperatorAuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DashboardOperatorController extends Controller
{
    /**
     * Create a tenant-local dashboard operator.
     *
     * Owner-only endpoint.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, OperatorAuditLogService $auditLogService): JsonResponse
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

        if ($actor === null || (string) $actor->operator_role !== User::ROLE_OWNER) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
                'message' => 'insufficient_operator_role',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'operator_role' => ['required', 'string', 'in:owner,admin,support'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $temporaryPassword = Str::random(16);

        $operator = User::query()->create([
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'company_id' => $companyId,
            'operator_role' => (string) $validated['operator_role'],
            'is_active' => true,
            'must_change_password' => true,
            'password' => Hash::make($temporaryPassword),
        ]);

        $auditLogService->record(
            $request,
            'operator.created',
            'user',
            (int) $operator->id,
            [
                'email' => (string) $operator->email,
                'operator_role' => (string) $operator->operator_role,
                'is_active' => (bool) $operator->is_active,
            ]
        );

        return response()->json([
            'ok' => true,
            'operator' => [
                'id' => $operator->id,
                'name' => $operator->name,
                'email' => $operator->email,
                'company_id' => $operator->company_id,
                'operator_role' => $operator->operator_role,
                'is_active' => (bool) $operator->is_active,
            ],
            'temporary_password' => $temporaryPassword,
            'note' => 'save_temporary_password_now',
        ]);
    }

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

        $validator = Validator::make($request->query(), [
            'operator_role' => ['nullable', 'string', 'in:owner,admin,support'],
            'is_active' => ['nullable', 'integer', 'in:0,1'],
            'sort_by' => ['nullable', 'string', 'in:id,name,email'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $sortBy = (string) ($validated['sort_by'] ?? 'id');
        $sortDir = (string) ($validated['sort_dir'] ?? 'asc');

        $query = User::query()
            ->where('company_id', $companyId);

        if (isset($validated['operator_role'])) {
            $query->where('operator_role', (string) $validated['operator_role']);
        }

        if (isset($validated['is_active'])) {
            $query->where('is_active', (int) $validated['is_active']);
        }

        $operators = $query
            ->orderBy($sortBy, $sortDir)
            ->get(['id', 'name', 'email', 'company_id', 'operator_role', 'is_active']);

        return response()->json([
            'ok' => true,
            'operators' => $operators->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'company_id' => $user->company_id,
                    'operator_role' => $user->operator_role,
                    'is_active' => (bool) $user->is_active,
                ];
            })->values(),
            'meta' => [
                'current_user_id' => $actor !== null ? $actor->id : null,
                'current_user_role' => $actor !== null ? $actor->operator_role : null,
                'can_manage_roles' => $actor !== null && (string) $actor->operator_role === User::ROLE_OWNER,
                'filters' => [
                    'operator_role' => $validated['operator_role'] ?? null,
                    'is_active' => isset($validated['is_active']) ? (int) $validated['is_active'] : null,
                    'sort_by' => $sortBy,
                    'sort_dir' => $sortDir,
                ],
            ],
        ]);
    }

    /**
     * Regenerate a temporary password for a tenant-local operator.
     *
     * Owner-only endpoint.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request, int $id, OperatorAuditLogService $auditLogService): JsonResponse
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
                'error' => 'cannot_reset_own_password',
            ], 422);
        }

        $temporaryPassword = Str::random(16);

        $target->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ]);
        $target->refresh();

        $auditLogService->record(
            $request,
            'operator.password_reset',
            'user',
            (int) $target->id,
            [
                'email' => (string) $target->email,
                'must_change_password' => true,
            ]
        );

        return response()->json([
            'ok' => true,
            'operator' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'company_id' => $target->company_id,
                'operator_role' => $target->operator_role,
                'is_active' => (bool) $target->is_active,
            ],
            'temporary_password' => $temporaryPassword,
            'note' => 'save_temporary_password_now',
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
    public function updateRole(Request $request, int $id, OperatorAuditLogService $auditLogService): JsonResponse
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

        $auditLogService->record(
            $request,
            'operator.role_updated',
            'user',
            (int) $target->id,
            [
                'old_role' => $oldRole,
                'new_role' => (string) $target->operator_role,
                'no_change' => $oldRole === $newRole,
            ]
        );

        return response()->json([
            'ok' => true,
            'operator' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'company_id' => $target->company_id,
                'operator_role' => $target->operator_role,
                'is_active' => (bool) $target->is_active,
            ],
            'no_change' => $oldRole === $newRole,
        ]);
    }

    /**
     * Update activation state for a tenant-local operator.
     *
     * Owner-only endpoint.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateActivation(Request $request, int $id, OperatorAuditLogService $auditLogService): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'is_active' => ['required', 'boolean'],
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

        $newActive = (bool) $validator->validated()['is_active'];
        $oldActive = (bool) $target->is_active;

        if ((int) $actor->id === (int) $target->id && !$newActive) {
            return response()->json([
                'ok' => false,
                'error' => 'cannot_deactivate_self',
            ], 422);
        }

        if (
            (string) $target->operator_role === User::ROLE_OWNER
            && $oldActive
            && !$newActive
        ) {
            $activeOwnerCount = User::query()
                ->where('company_id', $companyId)
                ->where('operator_role', User::ROLE_OWNER)
                ->where('is_active', true)
                ->count();

            if ($activeOwnerCount <= 1) {
                return response()->json([
                    'ok' => false,
                    'error' => 'last_active_owner_required',
                ], 422);
            }
        }

        if ($oldActive !== $newActive) {
            $target->update([
                'is_active' => $newActive,
            ]);
            $target->refresh();
        }

        $auditLogService->record(
            $request,
            'operator.activation_updated',
            'user',
            (int) $target->id,
            [
                'old_is_active' => $oldActive,
                'new_is_active' => (bool) $target->is_active,
                'no_change' => $oldActive === $newActive,
            ]
        );

        return response()->json([
            'ok' => true,
            'operator' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'company_id' => $target->company_id,
                'operator_role' => $target->operator_role,
                'is_active' => (bool) $target->is_active,
            ],
            'no_change' => $oldActive === $newActive,
        ]);
    }
}
