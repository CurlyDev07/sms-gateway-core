<?php

namespace App\Services;

use App\Models\OperatorAuditLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class OperatorAuditLogService
{
    /**
     * Record a dashboard operator audit event.
     *
     * This intentionally logs only dashboard/session traffic to avoid
     * changing machine API behavior on /api/* endpoints.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $action
     * @param string|null $targetType
     * @param int|null $targetId
     * @param array<string,mixed> $metadata
     * @return void
     */
    public function record(
        Request $request,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $metadata = []
    ): void {
        if (!$request->is('dashboard/api/*')) {
            return;
        }

        $companyId = TenantContext::companyId($request);
        $actor = $request->user();

        if ($companyId === null || $actor === null) {
            return;
        }

        OperatorAuditLog::query()->create([
            'company_id' => (int) $companyId,
            'actor_user_id' => (int) $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata,
        ]);
    }
}
