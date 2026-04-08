<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class OperatorDashboardPageController extends Controller
{
    /**
     * Render operator management page.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('dashboard.operators');
    }
}
