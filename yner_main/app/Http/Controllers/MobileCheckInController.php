<?php

namespace App\Http\Controllers;

use App\Enums\CheckInDirection;
use App\Enums\CheckInRejection;
use App\Enums\CheckInResult;
use App\Exceptions\MobileCheckInException;
use App\Models\Employee;
use App\Models\MobileCheckIn;
use App\Models\UserDevice;
use App\Services\GeofenceService;
use App\Services\MobileCheckInService;
use App\Services\WebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The mobile attendance channel's HTTP surface.
 *
 * `show` and `index` are Inertia pages; `assertionOptions` and `store` answer JSON because they
 * are called with fetch() from inside the WebAuthn handler chain, not as page visits.
 */
class MobileCheckInController extends Controller
{
    public function __construct(
        private readonly MobileCheckInService $checkIns,
        private readonly GeofenceService $geofence,
        private readonly WebAuthnService $webauthn,
    ) {}

    /**
     * The employee's own check-in page.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $employee = $user->employee()->with(['workSchedule', 'workLocation', 'department.workLocation'])->first();

        if ($employee === null || $employee->status !== 'active') {
            return Inertia::render('check-in/show', [
                'employee' => null,
                'webauthnRequired' => $this->webauthn->isRequired(),
            ]);
        }

        $location = $this->geofence->resolveLocationFor($employee);

        return Inertia::render('check-in/show', [
            'employee' => [
                'name' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
            ],
            'today' => $this->checkIns->todayState($employee),
            // Name and radius only. The centre coordinates stay on the server: publishing them
            // would hand anyone spoofing their GPS the exact point to spoof to.
            'location' => $location ? [
                'name' => $location->name,
                'address' => $location->address,
                'radius_meters' => $location->radius_meters,
            ] : null,
            'schedule' => $employee->workSchedule ? [
                'name' => $employee->workSchedule->name,
                'start_time' => substr((string) $employee->workSchedule->start_time, 0, 5),
                'end_time' => substr((string) $employee->workSchedule->end_time, 0, 5),
            ] : null,
            'windows' => $this->checkIns->scheduleWindows($employee),
            'activeDevices' => UserDevice::where('user_id', $user->id)
                ->usable($this->webauthn->rpId())
                ->count(),
            'maxAccuracyMeters' => (int) config('attendance.mobile.max_accuracy_meters'),
            'webauthnRequired' => $this->webauthn->isRequired(),
            'actions' => ['create' => $user->can('create', MobileCheckIn::class)],
        ]);
    }

    /**
     * Issue an assertion challenge for the check-in ceremony.
     */
    public function assertionOptions(Request $request): JsonResponse
    {
        $this->authorize('create', MobileCheckIn::class);

        return response()->json($this->webauthn->assertionOptions($request->user()));
    }

    /**
     * Record one check-in or check-out attempt.
     *
     * Note what is NOT accepted from the client: `punched_at` (the server clocks it, or a late
     * arrival is one edited field away from being on time) and `distance_meters` (computed
     * server-side from the raw coordinates, because a client-reported distance is exactly the
     * value an attacker would forge).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MobileCheckIn::class);

        $validated = $request->validate([
            'direction' => ['required', Rule::in(CheckInDirection::values())],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['required', 'numeric', 'min:0'],
            // Absent only when WEBAUTHN_REQUIRE is off; the service, not this validator, is
            // what enforces the hard block.
            'credential' => ['nullable', 'array'],
        ]);

        try {
            $checkIn = $this->checkIns->record(
                $request->user(),
                CheckInDirection::from($validated['direction']),
                $validated,
                $request,
            );
        } catch (MobileCheckInException $e) {
            return response()->json([
                'message' => $e->reason->message(),
                'reason' => $e->reason->value,
                // Returned for `outside_geofence` so the employee can see how far off they are
                // and walk closer, instead of retrying blindly or calling HR.
                'distance_meters' => $e->attempt?->distance_meters,
            ], 422);
        }

        return response()->json([
            'message' => $checkIn->direction === CheckInDirection::In
                ? 'Checked in at '.$checkIn->punched_at->format('H:i').'.'
                : 'Checked out at '.$checkIn->punched_at->format('H:i').'.',
            'direction' => $checkIn->direction->value,
            'punched_at' => $checkIn->punched_at->format('H:i'),
            'distance_meters' => $checkIn->distance_meters,
            'flagged' => $checkIn->flagged,
        ]);
    }

    /**
     * The Admin/HR audit log — every attempt, accepted and rejected.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MobileCheckIn::class);

        $filters = [
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'employee' => $request->integer('employee') ?: null,
            'result' => in_array($request->query('result'), CheckInResult::values(), true)
                ? $request->query('result')
                : null,
            'flagged' => $request->boolean('flagged'),
        ];

        $checkIns = MobileCheckIn::with(['employee', 'workLocation', 'userDevice'])
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('work_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('work_date', '<=', $to))
            ->when($filters['employee'], fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['result'], fn ($q, $result) => $q->where('result', $result))
            ->when($filters['flagged'], fn ($q) => $q->where('flagged', true))
            ->latest('punched_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MobileCheckIn $checkIn) => [
                'id' => $checkIn->id,
                'employee' => $checkIn->employee?->fullName(),
                'employee_code' => $checkIn->employee?->employee_code,
                'work_date' => $checkIn->work_date->toDateString(),
                'punched_at' => $checkIn->punched_at->format('Y-m-d H:i:s'),
                'direction' => $checkIn->direction->value,
                'direction_label' => $checkIn->direction->label(),
                'location' => $checkIn->workLocation?->name,
                'distance_meters' => $checkIn->distance_meters,
                'accuracy_meters' => $checkIn->accuracy_meters,
                'within_geofence' => $checkIn->within_geofence,
                'webauthn_verified' => $checkIn->webauthn_verified,
                'device_name' => $checkIn->userDevice?->device_name,
                'result' => $checkIn->result->value,
                'result_label' => $checkIn->result->label(),
                'rejection_reason' => $checkIn->rejection_reason?->value,
                'rejection_label' => $checkIn->rejection_reason?->label(),
                'flagged' => $checkIn->flagged,
                'flag_reason' => $checkIn->flag_reason,
                'ip_address' => $checkIn->ip_address,
            ]);

        $today = now()->toDateString();

        return Inertia::render('mobile-check-ins/index', [
            'checkIns' => $checkIns,
            'filters' => $filters,
            'employees' => Employee::orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'employee_code'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->fullName(),
                    'employee_code' => $e->employee_code,
                ]),
            'results' => array_map(
                fn (CheckInResult $r) => ['value' => $r->value, 'label' => $r->label()],
                CheckInResult::cases(),
            ),
            'stats' => [
                'today' => MobileCheckIn::whereDate('work_date', $today)->count(),
                'accepted_today' => MobileCheckIn::whereDate('work_date', $today)->accepted()->count(),
                'rejected_today' => MobileCheckIn::whereDate('work_date', $today)
                    ->where('result', CheckInResult::Rejected)->count(),
                'flagged' => MobileCheckIn::where('flagged', true)->count(),
            ],
            'rejectionReasons' => array_map(
                fn (CheckInRejection $r) => ['value' => $r->value, 'label' => $r->label()],
                CheckInRejection::cases(),
            ),
        ]);
    }
}
