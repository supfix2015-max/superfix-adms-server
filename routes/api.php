<?php

use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DeviceApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| JSON endpoints for administrative clients and webhook consumers.
*/

Route::prefix('device')->group(function () {
    Route::post('/register', [DeviceApiController::class, 'register']);
    Route::post('/ping', [DeviceApiController::class, 'ping']);
    Route::post('/logs', [DeviceApiController::class, 'logs']);
    Route::post('/command', [DeviceApiController::class, 'command']);
});

Route::prefix('dashboard')->group(function () {
    Route::get('/summary', [DashboardApiController::class, 'summary']);
    Route::get('/devices', [DashboardApiController::class, 'devices']);
    Route::get('/logs', [DashboardApiController::class, 'logs']);
});

Route::get('/health', [DeviceApiController::class, 'health']);
