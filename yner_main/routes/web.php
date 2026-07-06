<?php

use App\Http\Controllers\AiInsightController;
use App\Http\Controllers\AiSettingController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BiometricDeviceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DeviceEnrollmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PermissionRequestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportSummaryController;
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

Route::get('/ai-insights', [AiInsightController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('ai-insights.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export/csv', [ReportController::class, 'exportCsv'])->name('reports.export.csv');
    Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');

    Route::get('/report-summaries', [ReportSummaryController::class, 'index'])->name('report-summaries.index');
    Route::post('/report-summaries', [ReportSummaryController::class, 'store'])->name('report-summaries.store');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');
});

Route::middleware('auth')->group(function () {
    Route::put('/attendance/{attendanceRecord}', [AttendanceController::class, 'update'])->name('attendance.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/settings/ai', [AiSettingController::class, 'edit'])->name('ai-settings.edit');
    Route::put('/settings/ai', [AiSettingController::class, 'update'])->name('ai-settings.update');
    Route::post('/settings/ai/test', [AiSettingController::class, 'test'])->name('ai-settings.test');

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
    Route::post('biometric-devices/{biometricDevice}/test-connection', [BiometricDeviceController::class, 'testConnection'])
        ->name('biometric-devices.test-connection');
    Route::get('biometric-devices/{biometricDevice}/logs', [BiometricDeviceController::class, 'logs'])
        ->name('biometric-devices.logs');
    Route::resource('device-enrollments', DeviceEnrollmentController::class)->except(['show', 'create', 'edit']);
});

require __DIR__.'/auth.php';
