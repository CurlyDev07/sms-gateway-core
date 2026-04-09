<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the dashboard login form.
     *
     * @return \Illuminate\View\View
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming dashboard login request.
     *
     * Dashboard login requires a user account bound to a company tenant.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $remember = (bool) ($validated['remember'] ?? false);

        if (!Auth::attempt([
            'email' => (string) $validated['email'],
            'password' => (string) $validated['password'],
        ], $remember)) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors([
                    'email' => 'The provided credentials are incorrect.',
                ]);
        }

        $request->session()->regenerate();

        $user = $request->user();
        $companyId = $user !== null ? (int) ($user->company_id ?? 0) : 0;

        if ($companyId < 1 || !Company::query()->whereKey($companyId)->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withInput($request->except('password'))
                ->withErrors([
                    'email' => 'Your user account is not bound to a valid operator tenant.',
                ]);
        }

        if (!(bool) ($user->is_active ?? true)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withInput($request->except('password'))
                ->withErrors([
                    'email' => 'Your operator account is deactivated. Contact your tenant owner.',
                ]);
        }

        if ((bool) ($user->must_change_password ?? false)) {
            return redirect()->route('dashboard.password.change.show');
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated dashboard session.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
