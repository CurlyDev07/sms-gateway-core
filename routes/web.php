<?php

use App\Http\Controllers\SimFleetStatusPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard/sims', [SimFleetStatusPageController::class, 'index'])
    ->name('dashboard.sims.index');

// TEMPORARY DEBUG ROUTE — remove before any non-local deployment
if (app()->isLocal()) {
    Route::get('/_debug/log', function () {
        $path = storage_path('logs/laravel.log');
        if (!file_exists($path)) {
            return response('<pre>Log file not found: ' . e($path) . '</pre>', 404);
        }
        $lines = array_slice(file($path), -200);
        $output = e(implode('', $lines));
        return response(
            '<html><head><meta charset="utf-8"><title>Laravel Log</title>'
            . '<style>body{background:#111;color:#eee;margin:0;padding:1rem;font-size:13px;}'
            . 'pre{white-space:pre-wrap;word-break:break-all;}</style></head>'
            . '<body><pre>' . $output . '</pre></body></html>'
        );
    });
}
