<?php

use App\Http\Controllers\Api\BiometricDeviceListController;
use App\Http\Controllers\Api\BiometricIngestController;
use Illuminate\Support\Facades\Route;

Route::middleware('verify.internal-secret')->prefix('biometric')->group(function () {
    Route::post('/ingest', [BiometricIngestController::class, 'store']);
    Route::get('/devices', [BiometricDeviceListController::class, 'index']);
});
