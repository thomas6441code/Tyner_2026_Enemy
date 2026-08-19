<?php

use App\Http\Controllers\AccountInvitationController;
use App\Http\Controllers\AiInsightController;
use App\Http\Controllers\AiSettingController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BiometricDeviceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DeviceEnrollmentController;
use App\Http\Controllers\DeviceResetRequestController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\MobileCheckInController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PermissionRequestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationRequestReviewController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportSummaryController;
use App\Http\Controllers\UserDeviceController;
use App\Http\Controllers\WorkLocationController;
use App\Http\Controllers\WorkScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/export/excel', [AttendanceController::class, 'exportExcel'])
        ->name('attendance.export.excel');
    Route::get('/attendance/export/pdf', [AttendanceController::class, 'exportPdf'])
        ->name('attendance.export.pdf');
});

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
    Route::resource('work-locations', WorkLocationController::class)->except(['show', 'create', 'edit']);
    Route::resource('employees', EmployeeController::class)->except(['show', 'create', 'edit']);

    Route::resource('permission-requests', PermissionRequestController::class)->except(['show', 'create', 'edit']);
    Route::put('permission-requests/{permissionRequest}/review', [PermissionRequestController::class, 'review'])
        ->name('permission-requests.review');
    Route::get('permission-requests/{permissionRequest}/attachment', [PermissionRequestController::class, 'attachment'])
        ->name('permission-requests.attachment');

    // Domain actions rather than CRUD, following the permission-requests/{x}/review shape.
    Route::get('registration-requests', [RegistrationRequestReviewController::class, 'index'])
        ->name('registration-requests.index');
    Route::put('registration-requests/{registrationRequest}/approve', [RegistrationRequestReviewController::class, 'approve'])
        ->name('registration-requests.approve');
    Route::put('registration-requests/{registrationRequest}/reject', [RegistrationRequestReviewController::class, 'reject'])
        ->name('registration-requests.reject');

    Route::get('account-invitations', [AccountInvitationController::class, 'index'])
        ->name('account-invitations.index');
    Route::post('account-invitations/{accountInvitation}/resend', [AccountInvitationController::class, 'resend'])
        ->name('account-invitations.resend');
    Route::delete('account-invitations/{accountInvitation}', [AccountInvitationController::class, 'destroy'])
        ->name('account-invitations.destroy');

    // WebAuthn authenticators (phones). Distinct from `biometric-devices`, which are the
    // wall-mounted terminals — the two channels share nothing but the attendance pipeline.
    Route::get('devices', [UserDeviceController::class, 'index'])->name('devices.index');
    Route::delete('devices/{device}', [UserDeviceController::class, 'destroy'])->name('devices.destroy');

    // Registering a new authenticator is a privilege escalation on a hijacked session: it
    // hands the attacker a durable way to check in as the victim. Re-confirming the password
    // costs the legitimate owner one prompt and costs an attacker the whole attack.
    Route::middleware(['password.confirm', 'throttle:10,1'])->group(function () {
        Route::post('devices/register/options', [UserDeviceController::class, 'registerOptions'])
            ->name('devices.register.options');
        Route::post('devices/register/verify', [UserDeviceController::class, 'registerVerify'])
            ->name('devices.register.verify');
    });

    // The only way an account's single linked device can be changed. Employees may not revoke
    // their own — self-service unlinking would reduce the binding to a formality — so they ask
    // here and an Admin or HR Officer decides.
    Route::post('device-reset-requests', [DeviceResetRequestController::class, 'store'])
        ->middleware('throttle:5,60')
        ->name('device-reset-requests.store');
    Route::get('device-reset-requests', [DeviceResetRequestController::class, 'index'])
        ->name('device-reset-requests.index');
    Route::put('device-reset-requests/{deviceResetRequest}/approve', [DeviceResetRequestController::class, 'approve'])
        ->name('device-reset-requests.approve');
    Route::put('device-reset-requests/{deviceResetRequest}/reject', [DeviceResetRequestController::class, 'reject'])
        ->name('device-reset-requests.reject');

    // Mobile check-in: the second attendance channel. The write paths are throttled because
    // each one runs a WebAuthn ceremony and a geofence evaluation, and because a tight retry
    // loop is what a spoofing attempt looks like.
    Route::get('check-in', [MobileCheckInController::class, 'show'])->name('check-in.show');
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('check-in/assertion-options', [MobileCheckInController::class, 'assertionOptions'])
            ->name('check-in.assertion-options');
        Route::post('check-in', [MobileCheckInController::class, 'store'])->name('check-in.store');
    });
    Route::get('mobile-check-ins', [MobileCheckInController::class, 'index'])->name('mobile-check-ins.index');

    Route::resource('biometric-devices', BiometricDeviceController::class)->except(['show', 'create', 'edit']);
    Route::post('biometric-devices/{biometricDevice}/test-connection', [BiometricDeviceController::class, 'testConnection'])
        ->name('biometric-devices.test-connection');
    Route::get('biometric-devices/{biometricDevice}/logs', [BiometricDeviceController::class, 'logs'])
        ->name('biometric-devices.logs');
    Route::resource('device-enrollments', DeviceEnrollmentController::class)->except(['show', 'create', 'edit']);
});

require __DIR__.'/auth.php';
