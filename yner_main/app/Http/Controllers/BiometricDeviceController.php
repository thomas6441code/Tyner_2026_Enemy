<?php

namespace App\Http\Controllers;

use App\Models\BiometricDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ]);

        return Inertia::render('biometric-devices/index', [
            'devices' => $devices,
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
     * Shared validation rules for store/update.
     */
    private function validateDevice(Request $request, ?BiometricDevice $biometricDevice = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:stub,zkteco,hikvision'],
            'serial' => ['required', 'string', 'max:255', 'unique:biometric_devices,serial,'.($biometricDevice?->id)],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ]);
    }

    /**
     * Action permission flags for the index page (role-scalar, not per-row).
     */
    private function actions(Request $request): array
    {
        return [
            'create' => $request->user()->can('create', BiometricDevice::class),
            'update' => $request->user()->can('update', new BiometricDevice),
            'delete' => $request->user()->can('delete', new BiometricDevice),
        ];
    }
}
