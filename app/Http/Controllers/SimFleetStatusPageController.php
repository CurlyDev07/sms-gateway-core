<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SimFleetStatusPageController extends Controller
{
    /**
     * Render read-only SIM fleet status page.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('dashboard.sim-fleet-status');
    }
}

