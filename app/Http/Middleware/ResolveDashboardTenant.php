<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;

class ResolveDashboardTenant
{
    /**
     * Resolve tenant context for authenticated dashboard web requests.
     *
     * This uses a server-side session binding (`dashboard_api_client_id`) and
     * never trusts browser-supplied gateway API credentials.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\JsonResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $apiClientId = $request->session()->get('dashboard_api_client_id');

        if (!is_numeric($apiClientId) || (int) $apiClientId < 1) {
            return response()->json([
                'ok' => false,
                'error' => 'dashboard_tenant_not_bound',
            ], 403);
        }

        $apiClient = ApiClient::query()
            ->where('id', (int) $apiClientId)
            ->where('status', 'active')
            ->whereNotNull('company_id')
            ->first();

        if ($apiClient === null) {
            $request->session()->forget('dashboard_api_client_id');

            return response()->json([
                'ok' => false,
                'error' => 'dashboard_tenant_not_bound',
            ], 403);
        }

        $companyId = (int) $apiClient->company_id;

        app()->instance('tenant.company_id', $companyId);
        app()->instance('tenant.api_client', $apiClient);
        $request->attributes->set('tenant_company_id', $companyId);
        $request->attributes->set('api_client', $apiClient);

        return $next($request);
    }
}
