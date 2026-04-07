<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class AssignmentDashboardPageController extends Controller
{
    /**
     * Render read-only assignments dashboard page.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('dashboard.assignments');
    }
}

