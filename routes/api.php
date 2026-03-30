<?php

use App\Http\Controllers\GatewayInboundController;
use App\Http\Controllers\GatewayOutboundController;
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

    Route::get('/messages/status', function () {
        return response()->json(['ok' => false, 'error' => 'not_implemented'], 501);
    });

    Route::get('/sims', function () {
        return response()->json(['ok' => false, 'error' => 'not_implemented'], 501);
    });

    Route::get('/assignments', function () {
        return response()->json(['ok' => false, 'error' => 'not_implemented'], 501);
    });

    Route::post('/assignments/set', function () {
        return response()->json(['ok' => false, 'error' => 'not_implemented'], 501);
    });

    Route::post('/assignments/mark-safe', function () {
        return response()->json(['ok' => false, 'error' => 'not_implemented'], 501);
    });

    Route::post('/admin/rebalance', function () {
        return response()->json(['ok' => false, 'error' => 'not_implemented'], 501);
    });
});
