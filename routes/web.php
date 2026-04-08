<?php

use App\Http\Controllers\AssignmentDashboardPageController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardHomePageController;
use App\Http\Controllers\MigrationDashboardPageController;
use App\Http\Controllers\MessageStatusDashboardPageController;
use App\Http\Controllers\SimDetailControlPageController;
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

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardHomePageController::class, 'index'])
        ->name('dashboard.home');

    Route::get('/dashboard/sims', [SimFleetStatusPageController::class, 'index'])
        ->name('dashboard.sims.index');

    Route::get('/dashboard/assignments', [AssignmentDashboardPageController::class, 'index'])
        ->name('dashboard.assignments.index');

    Route::get('/dashboard/migration', [MigrationDashboardPageController::class, 'index'])
        ->name('dashboard.migration.index');

    Route::get('/dashboard/messages/status', [MessageStatusDashboardPageController::class, 'index'])
        ->name('dashboard.messages.status.index');

    Route::get('/dashboard/sims/{id}', [SimDetailControlPageController::class, 'show'])
        ->whereNumber('id')
        ->name('dashboard.sims.show');
});
