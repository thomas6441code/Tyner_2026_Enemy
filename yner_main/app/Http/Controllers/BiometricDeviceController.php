<?php

namespace App\Http\Controllers;

use App\Models\BiometricDevice;
use App\Models\DeviceEnrollment;
use App\Models\RawAttendanceLog;
use App\Services\BioServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BiometricDeviceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', BiometricDevice::class);

        $devices = BiometricDevice::withCount('enrollments')->orderBy('name')->paginate(15)
            ->through(fn (BiometricDevice $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'type' => $device->type,
                'serial' => $device->serial,
                'host' => $device->host,
                'port' => $device->port,
                'username' => $device->username,
                'status' => $device->status,
                'enrollments_count' => $device->enrollments_count,
                'last_checked_at' => $device->last_checked_at?->toIso8601String(),
                'last_status' => $device->last_status,
                'last_status_message' => $device->last_status_message,
            ]);

        return Inertia::render('biometric-devices/index', [
            'devices' => $devices,
            'stats' => $this->stats(),
            'actions' => $this->actions($request),
            'status' => session('status'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', BiometricDevice::class);

        $validated = $this->validateDevice($request);

        BiometricDevice::create($validated);

        return redirect()->route('biometric-devices.index')->with('status', 'Device created.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BiometricDevice $biometricDevice): RedirectResponse
    {
        $this->authorize('update', $biometricDevice);

        $validated = $this->validateDevice($request, $biometricDevice);

        if (($validated['password'] ?? '') === '') {
            unset($validated['password']);
        }

        $biometricDevice->update($validated);

        return redirect()->route('biometric-devices.index')->with('status', 'Device updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BiometricDevice $biometricDevice): RedirectResponse
    {
        $this->authorize('delete', $biometricDevice);

        $biometricDevice->delete();

        return redirect()->route('biometric-devices.index')->with('status', 'Device deleted.');
    }

    /**
     * Live connectivity check ("Test Connection") — asks bio-service to build the matching
     * driver and attempt connect()+health(), then persists the outcome so the index page's
     * status indicator reflects it on next load without re-testing.
     */
    public function testConnection(BiometricDevice $biometricDevice): JsonResponse
    {
        $this->authorize('update', $biometricDevice);

        $result = app(BioServiceClient::class)->testConnection($biometricDevice);

        if ($result === null) {
            return response()->json([
                'ok' => false,
                'error' => 'The biometric service is unreachable. Confirm bio-service is running and try again.',
            ], 502);
        }

        $biometricDevice->update([
            'last_checked_at' => now(),
            'last_status' => $result['ok'] ? 'connected' : 'error',
            'last_status_message' => $result['error'],
        ]);

        return response()->json($result);
    }

    /**
     * Recent raw punch logs ingested from this device, matched by serial (no FK — see
     * RawAttendanceLog), for the index page's "View Logs" dialog.
     */
    public function logs(BiometricDevice $biometricDevice): JsonResponse
    {
        $this->authorize('view', $biometricDevice);

        $logs = RawAttendanceLog::where('device_serial', $biometricDevice->serial)
            ->orderByDesc('punched_at')
            ->limit(50)
            ->get(['device_user_id', 'punched_at', 'processed_at']);

        return response()->json(['logs' => $logs]);
    }

    /**
     * Shared validation rules for store/update.
     */
    private function validateDevice(Request $request, ?BiometricDevice $biometricDevice = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:stub,zkteco,hikvision'],
            'serial' => [
                'required', 'string', 'max:255',
                'unique:biometric_devices,serial,'.($biometricDevice?->id),
                // The mobile channel writes this serial on every punch it records. A real
                // device claiming it would collide with mobile rows on the dedupe index and,
                // worse, make the enrollment lookup start matching mobile punches — silently
                // attributing them to whichever employee is enrolled on that device.
                Rule::notIn([RawAttendanceLog::MOBILE_SERIAL]),
            ],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ]);
    }

    /**
     * Summary counts for the stat cards on the index page.
     */
    private function stats(): array
    {
        return [
            'total' => BiometricDevice::count(),
            'active' => BiometricDevice::where('status', 'active')->count(),
            'enrollments' => DeviceEnrollment::count(),
        ];
    }

    /**
     * Action permission flags for the index page (role-scalar, not per-row).
     */
    private function actions(Request $request): array
    {
        return [
            'create' => $request->user()->can('create', BiometricDevice::class),
            'view' => $request->user()->can('view', new BiometricDevice),
            'update' => $request->user()->can('update', new BiometricDevice),
            'delete' => $request->user()->can('delete', new BiometricDevice),
        ];
    }
}
