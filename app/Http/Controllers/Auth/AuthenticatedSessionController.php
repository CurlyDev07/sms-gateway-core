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
     * Dashboard tenant context is resolved server-side and stored in session.
     * API key/secret are only used as an optional tenant selector when needed.
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

        $credentialsSupplied = (
            (string) ($validated['dashboard_api_key'] ?? '') !== ''
            || (string) ($validated['dashboard_api_secret'] ?? '') !== ''
        );

        $apiClient = $this->resolveDashboardApiClient($validated);

        if ($apiClient === null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withInput($request->except('password', 'dashboard_api_secret'))
                ->withErrors([
                    'dashboard_api_key' => $credentialsSupplied
                        ? 'Dashboard API credentials are invalid.'
                        : 'Unable to resolve dashboard tenant context. Provide Dashboard API credentials.',
                ]);
        }

        $request->session()->put('dashboard_api_client_id', (int) $apiClient->id);

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

    /**
     * Resolve the dashboard tenant API client for the current login session.
     *
     * Selection order:
     * 1) Explicit dashboard API credentials from login form.
     * 2) Single active API client in the system (safe default for single-tenant ops).
     *
     * @param array<string, mixed> $validated
     * @return \App\Models\ApiClient|null
     */
    private function resolveDashboardApiClient(array $validated): ?ApiClient
    {
        $apiKey = trim((string) ($validated['dashboard_api_key'] ?? ''));
        $apiSecret = trim((string) ($validated['dashboard_api_secret'] ?? ''));

        if ($apiKey !== '' && $apiSecret !== '') {
            $apiClient = ApiClient::query()
                ->where('api_key', $apiKey)
                ->where('status', 'active')
                ->whereNotNull('company_id')
                ->first();

            if ($apiClient === null || !Hash::check($apiSecret, (string) $apiClient->api_secret)) {
                return null;
            }

            return $apiClient;
        }

        $activeClients = ApiClient::query()
            ->where('status', 'active')
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($activeClients->count() === 1) {
            /** @var \App\Models\ApiClient $apiClient */
            $apiClient = $activeClients->first();
            return $apiClient;
        }

        return null;
    }
}
