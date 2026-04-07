<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DashboardHomePageController extends Controller
{
    /**
     * Render dashboard home/navigation page.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('dashboard.home');
    }
}
