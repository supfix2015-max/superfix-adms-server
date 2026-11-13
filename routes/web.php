<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\CommandController;
use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\DeviceConnectionStatusController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\LegacyAttendanceController;
use App\Http\Controllers\Admin\LegacyDirectoryController;
use App\Http\Controllers\Admin\LegacyFleetController;
use App\Http\Controllers\Admin\LegacyAttendanceReportController;
use App\Http\Controllers\Admin\UserSyncController;
use App\Http\Controllers\Admin\ZenHrIntegrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/dashboard');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    // AT2-only ZenHR integration; controller enforces host + enable flag.
    // OAuth callback matches the URL registered with ZenHR exactly.
    Route::get('/integrations/zenhr', [ZenHrIntegrationController::class, 'index'])
        ->name('zenhr.index');
    Route::post('/integrations/zenhr/connect', [ZenHrIntegrationController::class, 'connect'])
        ->name('zenhr.connect');
    Route::get('/integrations/zenhr/callback', [ZenHrIntegrationController::class, 'callback'])
        ->name('zenhr.callback');
    Route::post('/integrations/zenhr/test', [ZenHrIntegrationController::class, 'test'])
        ->name('zenhr.test');

    // Operations inspired by the legacy app run ONLY in the existing
    // ADMS Enterprise design. Original dbo is read-only; device preferences
    // are limited to the operational adms schema.
    Route::view('/biocloud', 'admin.legacy.home')->name('biocloud.home');
    Route::view('/biocloud/system', 'admin.legacy.system')->name('biocloud.system');
    Route::get('/directory/{section}', [LegacyDirectoryController::class, 'index'])
        ->name('directory.index');
    // Backward-compatible alias: there is one canonical device page.
    Route::redirect('/fleet', '/admin/devices', 302)->name('fleet.index');
    Route::post('/fleet/{device}/license-requirement', [LegacyFleetController::class, 'updateLicenseRequirement'])
        ->name('fleet.license');
    Route::post('/fleet/{device}/cancel-pending', [LegacyFleetController::class, 'cancelPendingCommands'])
        ->name('fleet.cancel-pending');
    Route::get('/attendance-reports/{report}', [LegacyAttendanceReportController::class, 'show'])
        ->name('attendance-reports.show');
    Route::get('/legacy', [LegacyAttendanceController::class, 'index'])->name('legacy.index');
    Route::get('/legacy/attendance/export', [LegacyAttendanceController::class, 'exportAttendance'])->name('legacy.attendance.export');
    Route::get('/device-connectivity', [DeviceConnectionStatusController::class, 'index'])->name('device-connectivity.index');
    Route::get('/connectivity', [DeviceConnectionStatusController::class, 'index'])->name('connectivity.index');

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
