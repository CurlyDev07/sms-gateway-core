<?php

use App\Http\Controllers\AssignmentDashboardPageController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AccountDashboardPageController;
use App\Http\Controllers\AuditLogDashboardPageController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\DashboardAuditLogController;
use App\Http\Controllers\DashboardHomePageController;
use App\Http\Controllers\DashboardOperatorController;
use App\Http\Controllers\InfotxtStatusController;
use App\Http\Controllers\MessageStatusController;
use App\Http\Controllers\MigrationDashboardPageController;
use App\Http\Controllers\MigrationController;
use App\Http\Controllers\MessageStatusDashboardPageController;
use App\Http\Controllers\OperatorDashboardPageController;
use App\Http\Controllers\OpsPanelController;
use App\Http\Controllers\PythonRuntimeController;
use App\Http\Controllers\PythonRuntimeDashboardPageController;
use App\Http\Controllers\SimAdminController;
use App\Http\Controllers\SimDetailControlPageController;
use App\Http\Controllers\SimFleetStatusPageController;
use App\Http\Controllers\SimController;
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

// Backward-compatible InfoText-style status endpoint (without /api prefix).
Route::get('/v2/status.php', [InfotxtStatusController::class, 'show']);

Route::prefix('ops')->group(function () {
    Route::get('/', [OpsPanelController::class, 'index'])->name('ops.index');
    Route::get('/data', [OpsPanelController::class, 'data'])->name('ops.data');
    Route::post('/retry-all-inbound', [OpsPanelController::class, 'retryAllInbound'])->name('ops.retry.inbound');
    Route::post('/retry-all-outbound', [OpsPanelController::class, 'retryAllOutbound'])->name('ops.retry.outbound');
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

Route::middleware(['auth', 'dashboard.password.changed'])->group(function () {
    Route::get('/dashboard/password/change', [ForcePasswordChangeController::class, 'show'])
        ->name('dashboard.password.change.show');
    Route::post('/dashboard/password/change', [ForcePasswordChangeController::class, 'update'])
        ->name('dashboard.password.change.update');

    Route::get('/dashboard/password', [ForcePasswordChangeController::class, 'showSelfService'])
        ->name('dashboard.password.self.show');
    Route::post('/dashboard/password', [ForcePasswordChangeController::class, 'updateSelfService'])
        ->name('dashboard.password.self.update');
});

Route::middleware(['auth', 'dashboard.password.changed'])->group(function () {
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

    Route::get('/dashboard/runtime/python', [PythonRuntimeDashboardPageController::class, 'index'])
        ->name('dashboard.runtime.python.index');

    Route::get('/dashboard/account', [AccountDashboardPageController::class, 'index'])
        ->name('dashboard.account.index');

    Route::get('/dashboard/operators', [OperatorDashboardPageController::class, 'index'])
        ->name('dashboard.operators.index');

    Route::get('/dashboard/audit', [AuditLogDashboardPageController::class, 'index'])
        ->name('dashboard.audit.index');

    Route::get('/dashboard/sims/{id}', [SimDetailControlPageController::class, 'show'])
        ->whereNumber('id')
        ->name('dashboard.sims.show');
});

Route::middleware(['auth', 'dashboard.password.changed', 'dashboard.tenant'])
    ->prefix('dashboard/api')
    ->group(function () {
        Route::get('/sims', [SimController::class, 'index']);

        Route::get('/assignments', [AssignmentController::class, 'index']);
        Route::get('/messages/status', [MessageStatusController::class, 'show']);
        Route::get('/runtime/python', [PythonRuntimeController::class, 'show']);
        Route::get('/operators', [DashboardOperatorController::class, 'index']);
        Route::get('/audit-logs', [DashboardAuditLogController::class, 'index']);

        Route::middleware('dashboard.operator.write')->group(function () {
            Route::post('/assignments/set', [AssignmentController::class, 'set']);
            Route::post('/assignments/mark-safe', [AssignmentController::class, 'markSafe']);
            Route::post('/runtime/python/send-test', [PythonRuntimeController::class, 'sendTest']);
            Route::post('/runtime/python/map-sim', [PythonRuntimeController::class, 'mapSim']);

            Route::post('/admin/sim/{id}/status', [SimAdminController::class, 'setStatus']);
            Route::post('/admin/sim/{id}/enable-assignments', [SimAdminController::class, 'enableAssignments']);
            Route::post('/admin/sim/{id}/disable-assignments', [SimAdminController::class, 'disableAssignments']);
            Route::post('/admin/sim/{id}/rebuild-queue', [SimAdminController::class, 'rebuildQueue']);

            Route::post('/admin/migrate-single-customer', [MigrationController::class, 'migrateSingleCustomer']);
            Route::post('/admin/migrate-bulk', [MigrationController::class, 'migrateBulk']);
            Route::post('/admin/rebalance', [MigrationController::class, 'rebalance']);
        });

        Route::middleware('dashboard.operator.owner')->group(function () {
            Route::post('/operators', [DashboardOperatorController::class, 'store']);
            Route::post('/operators/{id}/reset-password', [DashboardOperatorController::class, 'resetPassword'])
                ->whereNumber('id');
            Route::post('/operators/{id}/role', [DashboardOperatorController::class, 'updateRole'])
                ->whereNumber('id');
            Route::post('/operators/{id}/activation', [DashboardOperatorController::class, 'updateActivation'])
                ->whereNumber('id');
        });
    });
