<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\JsonResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        /** @var \App\Models\ApiClient|null $apiClient */
        $apiClient = $request->attributes->get('api_client');

        if (!$apiClient instanceof ApiClient) {
            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        if ($apiClient->company_id === null) {
            Log::warning('Tenant resolve rejected: API client has no company', [
                'api_client_id' => $apiClient->id,
                'path' => $request->path(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $company = $apiClient->company;

        if ($company === null || (string) $company->status !== 'active') {
            Log::warning('Tenant resolve rejected: inactive company', [
                'api_client_id' => $apiClient->id,
                'company_id' => $apiClient->company_id,
                'company_status' => optional($company)->status,
                'path' => $request->path(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        if ($request->has('company_id') && (int) $request->input('company_id') !== (int) $apiClient->company_id) {
            Log::warning('Tenant mismatch attempt blocked', [
                'api_client_id' => $apiClient->id,
                'resolved_company_id' => $apiClient->company_id,
                'request_company_id' => $request->input('company_id'),
                'path' => $request->path(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $companyId = (int) $apiClient->company_id;

        app()->instance('tenant.company_id', $companyId);
        app()->instance('tenant.api_client', $apiClient);
        $request->attributes->set('tenant_company_id', $companyId);

        Log::info('Tenant resolved', [
            'api_client_id' => $apiClient->id,
            'company_id' => $companyId,
            'path' => $request->path(),
        ]);

        return $next($request);
    }
}
