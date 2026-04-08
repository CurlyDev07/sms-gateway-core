<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

class ResolveDashboardTenant
{
    /**
     * Resolve tenant context for authenticated dashboard web requests from
     * the logged-in operator account.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\JsonResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $companyId = $user !== null ? (int) ($user->company_id ?? 0) : 0;

        if ($companyId < 1) {
            return response()->json([
                'ok' => false,
                'error' => 'dashboard_tenant_not_bound',
            ], 403);
        }

        if (!Company::query()->whereKey($companyId)->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'dashboard_tenant_not_bound',
            ], 403);
        }

        app()->instance('tenant.company_id', $companyId);
        $request->attributes->set('tenant_company_id', $companyId);

        return $next($request);
    }
}
