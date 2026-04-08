<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
     * Optional dashboard API credentials can be provided once here and will
     * be bootstrapped into dashboard localStorage after redirect.
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
            'dashboard_api_key' => ['nullable', 'string', 'max:255', 'required_with:dashboard_api_secret'],
            'dashboard_api_secret' => ['nullable', 'string', 'max:255', 'required_with:dashboard_api_key'],
        ]);

        $remember = (bool) ($validated['remember'] ?? false);

        if (!Auth::attempt([
            'email' => (string) $validated['email'],
            'password' => (string) $validated['password'],
        ], $remember)) {
            return back()
                ->withInput($request->except('password', 'dashboard_api_secret'))
                ->withErrors([
                    'email' => 'The provided credentials are incorrect.',
                ]);
        }

        $request->session()->regenerate();

        $apiKey = (string) ($validated['dashboard_api_key'] ?? '');
        $apiSecret = (string) ($validated['dashboard_api_secret'] ?? '');

        if ($apiKey !== '' && $apiSecret !== '') {
            $apiClient = ApiClient::query()
                ->where('api_key', $apiKey)
                ->where('status', 'active')
                ->first();

            if ($apiClient === null || !Hash::check($apiSecret, (string) $apiClient->api_secret)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()
                    ->withInput($request->except('password', 'dashboard_api_secret'))
                    ->withErrors([
                        'dashboard_api_key' => 'Dashboard API credentials are invalid.',
                    ]);
            }

            $request->session()->flash('dashboard_api_credentials_bootstrap', [
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ]);
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
