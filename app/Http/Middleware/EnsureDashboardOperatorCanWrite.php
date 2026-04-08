<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureDashboardOperatorCanWrite
{
    /**
     * Ensure the authenticated dashboard operator has write permissions.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\JsonResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null || !$user->canDashboardWrite()) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
                'message' => 'insufficient_operator_role',
            ], 403);
        }

        return $next($request);
    }
}
