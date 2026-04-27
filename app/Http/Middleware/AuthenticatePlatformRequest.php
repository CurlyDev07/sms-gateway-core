<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthenticatePlatformRequest
{
    /**
     * Handle an incoming platform server-to-server request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\JsonResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $configuredKey = (string) config('services.chat_app.platform_key', '');
        $configuredSecret = (string) config('services.chat_app.platform_secret', '');

        if ($configuredKey === '' || $configuredSecret === '') {
            Log::warning('Platform auth rejected: missing gateway platform configuration', [
                'path' => $request->path(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'platform_auth_not_configured',
            ], 503);
        }

        $key = (string) $request->header('X-Platform-Key', '');
        $timestamp = (string) $request->header('X-Platform-Timestamp', '');
        $signature = (string) $request->header('X-Platform-Signature', '');

        if ($key === '' || $timestamp === '' || $signature === '') {
            return $this->reject($request, 'missing_platform_signature');
        }

        if (!hash_equals($configuredKey, $key)) {
            return $this->reject($request, 'invalid_platform_key');
        }

        if (!ctype_digit($timestamp)) {
            return $this->reject($request, 'invalid_platform_timestamp');
        }

        $tolerance = (int) config('services.chat_app.platform_timestamp_tolerance_seconds', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->reject($request, 'stale_platform_timestamp');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $configuredSecret);

        if (!hash_equals($expected, $signature)) {
            return $this->reject($request, 'invalid_platform_signature');
        }

        return $next($request);
    }

    /**
     * Return a normalized platform auth rejection.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $reason
     * @return \Illuminate\Http\JsonResponse
     */
    protected function reject(Request $request, string $reason)
    {
        Log::warning('Platform auth rejected', [
            'reason' => $reason,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => false,
            'error' => 'unauthorized',
            'reason' => $reason,
        ], 401);
    }
}
