<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsureDashboardOperatorIsOwner
{
    /**
     * Ensure the authenticated dashboard operator is an owner.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\JsonResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null || (string) $user->operator_role !== User::ROLE_OWNER) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
                'message' => 'insufficient_operator_role',
            ], 403);
        }

        return $next($request);
    }
}
