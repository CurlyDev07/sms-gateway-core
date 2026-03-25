<?php

namespace App\Support;

use App\Models\ApiClient;
use Illuminate\Http\Request;

class TenantContext
{
    /**
     * Get resolved tenant company id from request attributes.
     *
     * @param \Illuminate\Http\Request $request
     * @return int|null
     */
    public static function companyId(Request $request): ?int
    {
        if (app()->bound('tenant.company_id')) {
            return (int) app('tenant.company_id');
        }

        $value = $request->attributes->get('tenant_company_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * Get authenticated API client from request attributes.
     *
     * @param \Illuminate\Http\Request $request
     * @return \App\Models\ApiClient|null
     */
    public static function apiClient(Request $request): ?ApiClient
    {
        if (app()->bound('tenant.api_client')) {
            /** @var mixed $client */
            $client = app('tenant.api_client');
            return $client instanceof ApiClient ? $client : null;
        }

        $value = $request->attributes->get('api_client');

        return $value instanceof ApiClient ? $value : null;
    }
}
