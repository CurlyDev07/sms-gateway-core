<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureDashboardPasswordChanged
{
    /**
     * Enforce first-login password change for dashboard operators.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\JsonResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null || !(bool) ($user->must_change_password ?? false)) {
            return $next($request);
        }

        if ($request->routeIs('dashboard.password.change.show') || $request->routeIs('dashboard.password.change.update')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('dashboard/api/*')) {
            return response()->json([
                'ok' => false,
                'error' => 'password_change_required',
                'message' => 'temporary_password_must_be_changed',
            ], 423);
        }

        return redirect()->route('dashboard.password.change.show');
    }
}
