<?php

namespace App\Http\Middleware;

use App\Models\BiometricDevice;
use App\Models\Department;
use App\Models\DeviceEnrollment;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ] : null,
            ],
            'can' => [
                'viewDepartments' => $user?->can('viewAny', Department::class) ?? false,
                'viewEmployees' => $user?->can('viewAny', Employee::class) ?? false,
                'viewWorkSchedules' => $user?->can('viewAny', WorkSchedule::class) ?? false,
                'viewBiometricDevices' => $user?->can('viewAny', BiometricDevice::class) ?? false,
                'viewDeviceEnrollments' => $user?->can('viewAny', DeviceEnrollment::class) ?? false,
            ],
            'flash' => [
                'status' => $request->session()->get('status'),
            ],
        ];
    }
}
