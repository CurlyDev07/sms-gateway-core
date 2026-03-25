<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthenticateApiClient
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
        $apiKey = (string) $request->header('X-API-KEY', '');
        $apiSecret = (string) $request->header('X-API-SECRET', '');

        if ($apiKey === '' || $apiSecret === '') {
            Log::warning('API client auth failed: missing credentials', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        $apiClient = ApiClient::query()
            ->where('api_key', $apiKey)
            ->first();

        if ($apiClient === null || !Hash::check($apiSecret, (string) $apiClient->api_secret)) {
            Log::warning('API client auth failed: invalid credentials', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        if ($apiClient->status !== 'active') {
            Log::warning('API client auth rejected: inactive client', [
                'api_client_id' => $apiClient->id,
                'path' => $request->path(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $request->attributes->set('api_client', $apiClient);

        return $next($request);
    }
}
