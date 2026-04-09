<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountDashboardPageController extends Controller
{
    /**
     * Render read-only account profile page for the logged-in operator.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request): View
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $user->loadMissing('company');

        return view('dashboard.account', [
            'user' => $user,
        ]);
    }
}
