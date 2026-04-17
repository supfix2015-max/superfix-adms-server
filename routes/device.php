<?php

use App\Http\Controllers\IclockController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST'], '/iclock/cdata', [IclockController::class, 'cdata']);
Route::match(['GET', 'POST'], '/iclock/getrequest', [IclockController::class, 'getrequest']);
Route::match(['GET', 'POST'], '/iclock/devicecmd', [IclockController::class, 'devicecmd']);
Route::match(['GET', 'POST'], '/iclock/test', [IclockController::class, 'test']);
