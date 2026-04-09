<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ForcePasswordChangeController extends Controller
{
    /**
     * Show the first-login password change form.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (!(bool) ($user->must_change_password ?? false)) {
            return redirect()->route('dashboard.home');
        }

        return view('auth.force-password-change');
    }

    /**
     * Update password and clear first-login password-change requirement.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (!(bool) ($user->must_change_password ?? false)) {
            return redirect()->route('dashboard.home');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $newPassword = (string) $validated['password'];

        if (Hash::check($newPassword, (string) $user->password)) {
            return back()->withErrors([
                'password' => 'New password must be different from your temporary password.',
            ]);
        }

        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => false,
        ]);

        return redirect()->route('dashboard.home');
    }

    /**
     * Show self-service password change form for authenticated dashboard operators.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function showSelfService(Request $request)
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('auth.self-password-change');
    }

    /**
     * Update password for authenticated dashboard operator using current password check.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateSelfService(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $currentPassword = (string) $validated['current_password'];
        $newPassword = (string) $validated['password'];

        if (!Hash::check($currentPassword, (string) $user->password)) {
            return back()->withErrors([
                'current_password' => 'Current password is incorrect.',
            ]);
        }

        if (Hash::check($newPassword, (string) $user->password)) {
            return back()->withErrors([
                'password' => 'New password must be different from your current password.',
            ]);
        }

        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => false,
        ]);

        return redirect()
            ->route('dashboard.password.self.show')
            ->with('status', 'Password updated successfully.');
    }
}
