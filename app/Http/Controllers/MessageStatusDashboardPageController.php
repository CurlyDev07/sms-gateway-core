<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MessageStatusDashboardPageController extends Controller
{
    /**
     * Render read-only message status lookup dashboard page.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('dashboard.message-status');
    }
}
