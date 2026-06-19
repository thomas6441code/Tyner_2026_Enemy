<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use Illuminate\Http\JsonResponse;

class BiometricDeviceListController extends Controller
{
    /**
     * List active biometric devices for bio-service's poll registry.
     */
    public function index(): JsonResponse
    {
        $devices = BiometricDevice::where('status', 'active')
            ->get(['id', 'name', 'type', 'serial', 'host', 'port', 'username', 'password']);

        return response()->json(['devices' => $devices]);
    }
}
