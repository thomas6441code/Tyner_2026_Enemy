<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BiometricDeviceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DeviceEnrollmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\PermissionRequestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/attendance', [AttendanceController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('attendance.index');

Route::middleware('auth')->group(function () {
    Route::put('/attendance/{attendanceRecord}', [AttendanceController::class, 'update'])->name('attendance.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Forms are rendered as modals on each index page, so create/edit GET pages are unused.
    Route::resource('departments', DepartmentController::class)->except(['show', 'create', 'edit']);
    Route::resource('work-schedules', WorkScheduleController::class)->except(['show', 'create', 'edit']);
    Route::resource('employees', EmployeeController::class)->except(['show', 'create', 'edit']);

    Route::resource('permission-requests', PermissionRequestController::class)->except(['show', 'create', 'edit']);
    Route::put('permission-requests/{permissionRequest}/review', [PermissionRequestController::class, 'review'])
        ->name('permission-requests.review');
    Route::get('permission-requests/{permissionRequest}/attachment', [PermissionRequestController::class, 'attachment'])
        ->name('permission-requests.attachment');

    Route::resource('biometric-devices', BiometricDeviceController::class)->except(['show', 'create', 'edit']);
    Route::resource('device-enrollments', DeviceEnrollmentController::class)->except(['show', 'create', 'edit']);
});

require __DIR__.'/auth.php';
