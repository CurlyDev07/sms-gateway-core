<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class AuditLogDashboardPageController extends Controller
{
    /**
     * Render audit log dashboard page.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('dashboard.audit-log');
    }
}
