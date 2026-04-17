<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthenticateInfotxtClient
{
    /**
     * Authenticate an InfoText-style form request using UserID/ApiKey body fields.
     *
     * UserID maps to api_clients.api_key.
     * ApiKey maps to plain api_secret (checked against hashed api_clients.api_secret).
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\JsonResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $userId = trim((string) $request->input('UserID', ''));
        $apiKey = trim((string) $request->input('ApiKey', ''));

        if ($userId === '' || $apiKey === '') {
            Log::warning('InfoText-style auth failed: missing credentials', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'status' => '99',
                'message' => 'unauthorized',
            ], 401);
        }

        $apiClient = ApiClient::query()
            ->where('api_key', $userId)
            ->first();

        if ($apiClient === null || !Hash::check($apiKey, (string) $apiClient->api_secret)) {
            Log::warning('InfoText-style auth failed: invalid credentials', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'status' => '99',
                'message' => 'unauthorized',
            ], 401);
        }

        if ((string) $apiClient->status !== 'active') {
            Log::warning('InfoText-style auth rejected: inactive client', [
                'api_client_id' => $apiClient->id,
                'path' => $request->path(),
            ]);

            return response()->json([
                'status' => '99',
                'message' => 'forbidden',
            ], 403);
        }

        $request->attributes->set('api_client', $apiClient);

        return $next($request);
    }
}

