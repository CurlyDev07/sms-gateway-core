<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PythonRuntimeDashboardPageController extends Controller
{
    /**
     * Render Python runtime connectivity/discovery dashboard page.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('dashboard.python-runtime');
    }
}
