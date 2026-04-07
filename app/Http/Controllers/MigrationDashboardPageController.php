<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MigrationDashboardPageController extends Controller
{
    /**
     * Render migration dashboard page.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('dashboard.migration');
    }
}

