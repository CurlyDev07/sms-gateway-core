<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SimDetailControlPageController extends Controller
{
    /**
     * Render SIM detail/control page.
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function show(int $id): View
    {
        return view('dashboard.sim-detail-control', [
            'simId' => $id,
        ]);
    }
}

