<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\GatewayInboundController;
use App\Http\Controllers\GatewayOutboundController;
use App\Http\Controllers\MessageStatusController;
use App\Http\Controllers\MigrationController;
use App\Http\Controllers\SimAdminController;
use App\Http\Controllers\SimController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Internal modem/ingest trust path: not API-client authenticated.
Route::post('/gateway/inbound', [GatewayInboundController::class, 'store']);

// Tenant-authenticated API surface: company context is resolved from api_clients credentials.
Route::middleware(['api.client', 'tenant.resolve'])->group(function () {
    Route::post('/messages/send', [GatewayOutboundController::class, 'store']);

    Route::post('/messages/bulk', function () {
        return response()->json(['ok' => false, 'error' => 'not_implemented'], 501);
    });

    Route::get('/messages/status', [MessageStatusController::class, 'show']);

    Route::get('/sims', [SimController::class, 'index']);

    Route::get('/assignments', [AssignmentController::class, 'index']);

    Route::post('/assignments/set', [AssignmentController::class, 'set']);

    Route::post('/assignments/mark-safe', [AssignmentController::class, 'markSafe']);

    Route::post('/admin/sim/{id}/status', [SimAdminController::class, 'setStatus']);
    Route::post('/admin/sim/{id}/enable-assignments', [SimAdminController::class, 'enableAssignments']);
    Route::post('/admin/sim/{id}/disable-assignments', [SimAdminController::class, 'disableAssignments']);
    Route::post('/admin/sim/{id}/rebuild-queue', [SimAdminController::class, 'rebuildQueue']);

    Route::post('/admin/migrate-single-customer', [MigrationController::class, 'migrateSingleCustomer']);
    Route::post('/admin/migrate-bulk', [MigrationController::class, 'migrateBulk']);

    Route::post('/admin/rebalance', function () {
        return response()->json(['ok' => false, 'error' => 'not_implemented'], 501);
    });
});
