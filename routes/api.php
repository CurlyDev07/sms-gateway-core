<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\GatewayInboundController;
use App\Http\Controllers\GatewayOutboundController;
use App\Http\Controllers\InfotxtOutboundController;
use App\Http\Controllers\InfotxtStatusController;
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

// InfoText-compatible outbound adapter path for ChatApp fast-path integration.
Route::middleware(['infotxt.client', 'tenant.resolve', 'throttle:infotxt-send'])
    ->withoutMiddleware(['throttle:api'])
    ->post('/v2/send.php', [InfotxtOutboundController::class, 'store']);

// InfoText-compatible status poll path for ChatApp scheduler.
Route::get('/v2/status.php', [InfotxtStatusController::class, 'show']);

// Tenant-authenticated API surface: company context is resolved from api_clients credentials.
Route::middleware(['api.client', 'tenant.resolve'])->group(function () {
    Route::post('/messages/send', [GatewayOutboundController::class, 'store']);
    Route::post('/messages/bulk', [GatewayOutboundController::class, 'bulk']);

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

    Route::post('/admin/rebalance', [MigrationController::class, 'rebalance']);
});
