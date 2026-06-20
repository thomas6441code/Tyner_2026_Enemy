<?php

namespace App\Http\Controllers;

use App\Models\BiometricDevice;
use App\Models\DeviceEnrollment;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeviceEnrollmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DeviceEnrollment::class);

        $enrollments = DeviceEnrollment::with(['employee', 'biometricDevice'])
            ->orderBy('id')
            ->paginate(15)
            ->through(fn (DeviceEnrollment $enrollment) => [
                'id' => $enrollment->id,
                'device_user_id' => $enrollment->device_user_id,
                'biometric_device_id' => $enrollment->biometric_device_id,
                'employee_id' => $enrollment->employee_id,
                'employee' => $enrollment->employee ? ['fullName' => $enrollment->employee->fullName()] : null,
                'biometricDevice' => $enrollment->biometricDevice ? [
                    'name' => $enrollment->biometricDevice->name,
                    'serial' => $enrollment->biometricDevice->serial,
                ] : null,
            ]);

        return Inertia::render('device-enrollments/index', $this->formData() + [
            'enrollments' => $enrollments,
            'actions' => $this->actions($request),
            'status' => session('status'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', DeviceEnrollment::class);

        $validated = $this->validateEnrollment($request);

        DeviceEnrollment::create($validated);

        return redirect()->route('device-enrollments.index')->with('status', 'Enrollment created.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, DeviceEnrollment $deviceEnrollment): RedirectResponse
    {
        $this->authorize('update', $deviceEnrollment);

        $validated = $this->validateEnrollment($request, $deviceEnrollment);

        $deviceEnrollment->update($validated);

        return redirect()->route('device-enrollments.index')->with('status', 'Enrollment updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DeviceEnrollment $deviceEnrollment): RedirectResponse
    {
        $this->authorize('delete', $deviceEnrollment);

        $deviceEnrollment->delete();

        return redirect()->route('device-enrollments.index')->with('status', 'Enrollment deleted.');
    }

    /**
     * Shared validation rules for store/update.
     */
    private function validateEnrollment(Request $request, ?DeviceEnrollment $deviceEnrollment = null): array
    {
        return $request->validate([
            'biometric_device_id' => ['required', 'exists:biometric_devices,id'],
            'employee_id' => ['required', 'exists:employees,id'],
            'device_user_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('device_enrollments')
                    ->where('biometric_device_id', $request->input('biometric_device_id'))
                    ->ignore($deviceEnrollment?->id),
            ],
        ]);
    }

    /**
     * Reference data shared by the create/edit forms.
     */
    private function formData(): array
    {
        return [
            'employees' => Employee::orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (Employee $employee) => ['id' => $employee->id, 'fullName' => $employee->fullName()]),
            'devices' => BiometricDevice::where('status', 'active')->orderBy('name')->get(['id', 'name', 'serial']),
        ];
    }

    /**
     * Action permission flags for the index page (role-scalar, not per-row).
     */
    private function actions(Request $request): array
    {
        return [
            'create' => $request->user()->can('create', DeviceEnrollment::class),
            'update' => $request->user()->can('update', new DeviceEnrollment),
            'delete' => $request->user()->can('delete', new DeviceEnrollment),
        ];
    }
}
