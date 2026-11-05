<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\CommandController;
use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\UserSyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/dashboard');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('devices', DeviceController::class);
    Route::post('devices/{device}/refresh-token', [DeviceController::class, 'refreshToken'])->name('devices.refresh-token');

    Route::resource('employees', EmployeeController::class);
    Route::post('employees/{employee}/biometric-enrollment', [EmployeeController::class, 'enrollBiometric'])->name('employees.biometric-enrollment');
    Route::get('employees/{employee}/biometric-enrollment-status/{command}', [EmployeeController::class, 'biometricEnrollmentStatus'])->name('employees.biometric-enrollment-status');
    Route::post('employees/{employee}/push-biometrics', [EmployeeController::class, 'pushBiometrics'])->name('employees.push-biometrics');
    Route::prefix('credentials')->name('credentials.')->group(function () {
        Route::get('/', [CredentialController::class, 'index'])->name('index');
        Route::post('/', [CredentialController::class, 'store'])->name('store');
        Route::post('/biometric-enrollment', [CredentialController::class, 'enrollBiometric'])->name('biometric-enrollment');
        Route::delete('/{employee}/{type}', [CredentialController::class, 'destroy'])->name('destroy');
        Route::get('/no-credential', [CredentialController::class, 'noCredential'])->name('no-credential');
    });
    Route::prefix('user-syncs')->name('user-syncs.')->group(function () {
        Route::post('/', [UserSyncController::class, 'store'])->name('store');
        Route::get('/status', [UserSyncController::class, 'status'])->name('status');
    });

    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');

    Route::post('devices/{device}/command', [DeviceController::class, 'queueCommand'])->name('devices.command');
    Route::post('devices/{device}/pull', [DeviceController::class, 'pullData'])->name('devices.pull');
    Route::resource('commands', CommandController::class)->only(['index', 'create', 'store', 'show']);
    Route::resource('logs', LogController::class)->only(['index']);
    Route::get('logs/{id}/raw', [LogController::class, 'rawPayload'])->name('logs.raw');
});

require __DIR__.'/auth.php';

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('devices/attendance', [DeviceController::class, 'Attendance'])->name('devices.Attendance');
    Route::get('devices/daily', [DeviceController::class, 'daily'])->name('devices.daily');
    Route::get('devices/monthly', [DeviceController::class, 'monthly'])->name('devices.monthly');
    Route::get('devices/attendance/ajax', [DeviceController::class, 'getAttendance'])->name('devices.getAttendance');
    Route::get('devices/daily/ajax', [DeviceController::class, 'getDailyAttendanceSummary'])->name('devices.getDailyAttendanceSummary');
    Route::get('devices/monthly/ajax', [DeviceController::class, 'getMonthlyAttendanceSummary'])->name('devices.getMonthlyAttendanceSummary');
});
