<?php

namespace App\Services;

use App\Enums\AttendanceSource;
use App\Enums\CheckInDirection;
use App\Enums\CheckInRejection;
use App\Enums\CheckInResult;
use App\Enums\RoleName;
use App\Exceptions\MobileCheckInException;
use App\Jobs\ComputeAttendanceForDate;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MobileCheckIn;
use App\Models\RawAttendanceLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkLocation;
use App\Notifications\SystemNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * The mobile attendance channel: a phone acting as the terminal.
 *
 * `record()` is a strict, ordered, fail-closed gate chain. Every gate that refuses writes a
 * `mobile_check_ins` row with `result = rejected` and a reason, then throws — nothing is
 * silently dropped, because the audit trail is the actual security control here. Only if every
 * gate passes is a `raw_attendance_logs` row written, and from that point the punch is
 * indistinguishable from a biometric one to everything downstream.
 *
 * WHAT THIS CHANNEL DOES AND DOES NOT PROVE. The WebAuthn assertion proves that a specific
 * registered device, unlocked by its owner's fingerprint or face, made this request. The
 * coordinates prove nothing at all — `navigator.geolocation` is client-supplied and spoofable
 * with a devtools sensor override or a mock-location app. The geofence is therefore a speed
 * bump, not a barrier, and mobile check-in is a supplement to the biometric channel rather than
 * a replacement for it. That is a product constraint to communicate, not a bug to fix here.
 */
class MobileCheckInService
{
    public function __construct(
        private readonly GeofenceService $geofence,
        private readonly WebAuthnService $webauthn,
    ) {}

    /**
     * Run one check-in or check-out attempt end to end.
     *
     * @param  array<string, mixed>  $payload  latitude, longitude, accuracy_meters, credential
     *
     * @throws MobileCheckInException on any refusal — the attempt is persisted first
     */
    public function record(User $user, CheckInDirection $direction, array $payload, ?Request $request = null): MobileCheckIn
    {
        // Snapshot of everything known so far. Each gate adds to it, so a rejection row carries
        // as much context as the attempt actually reached — a row rejected at the geofence
        // still records the device that asserted and the real distance.
        $attempt = [
            'user_id' => $user->id,
            'direction' => $direction,
            'punched_at' => now(),
            'work_date' => now()->toDateString(),
            'latitude' => $this->coordinate($payload, 'latitude'),
            'longitude' => $this->coordinate($payload, 'longitude'),
            'accuracy_meters' => isset($payload['accuracy_meters']) && is_numeric($payload['accuracy_meters'])
                ? (int) round((float) $payload['accuracy_meters'])
                : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? mb_substr($request->userAgent(), 0, 250) : null,
        ];

        // ---- Gate 1: an active employee record -------------------------------------------
        // Attendance is computed per Employee, not per User. An account with no linked active
        // employee has nowhere for a punch to land.
        $employee = $user->employee()->with(['workSchedule', 'workLocation', 'department.workLocation'])->first();

        if ($employee === null || $employee->status !== 'active') {
            $this->refuse($attempt, CheckInRejection::NoEmployeeRecord);
        }

        $attempt['employee_id'] = $employee->id;

        // ---- Gate 2: WebAuthn assertion --------------------------------------------------
        // Verified against the challenge this server put in the session, which the service
        // deletes as it reads it. A client claim of "my device is verified" is never accepted:
        // with WEBAUTHN_REQUIRE on (the default), no valid assertion means no check-in.
        $device = $this->verifyDevice($user, $payload, $attempt, $request);

        $attempt['user_device_id'] = $device?->id;
        $attempt['webauthn_verified'] = $device !== null;

        // ---- Gate 3: GPS accuracy --------------------------------------------------------
        // A coarse fix derived from IP or a cell tower can be kilometres out, and would sail
        // through a 150m geofence on luck alone. Reject before the fix is ever compared.
        $maxAccuracy = (int) config('attendance.mobile.max_accuracy_meters');

        if ($attempt['accuracy_meters'] === null || $attempt['accuracy_meters'] > $maxAccuracy) {
            $this->refuse($attempt, CheckInRejection::LowGpsAccuracy);
        }

        // ---- Gate 4: a work location to measure against ----------------------------------
        // FAIL CLOSED. There is no global default location on purpose — one would geofence
        // every unconfigured employee against head office and produce attendance that looks
        // right and is wrong, the worst outcome available here.
        $location = $this->geofence->resolveLocationFor($employee);

        if ($location === null) {
            $this->refuse($attempt, CheckInRejection::NoWorkLocation);
        }

        $attempt['work_location_id'] = $location->id;

        // ---- Gate 5: the geofence --------------------------------------------------------
        // Distance is computed HERE, from the raw coordinates. Any distance the client sent is
        // display-only and is never persisted.
        $distance = $this->geofence->distanceTo($location, $attempt['latitude'], $attempt['longitude']);

        $attempt['distance_meters'] = (int) round($distance);
        $attempt['within_geofence'] = $distance <= (float) $location->radius_meters;

        if (! $attempt['within_geofence']) {
            // The row keeps the real distance. Recording how far outside someone was is the
            // entire reason this table exists.
            $this->refuse($attempt, CheckInRejection::OutsideGeofence);
        }

        // ---- Gate 6: the schedule window -------------------------------------------------
        $this->assertWithinScheduleWindow($employee, $direction, $attempt);

        // ---- Gates 7-10: ordering, duplication, and the writes ---------------------------
        $checkIn = $this->commit($employee, $direction, $attempt);

        // ---- Gate 11: audit --------------------------------------------------------------
        $this->audit($user, $checkIn, $location);

        // ---- Gate 12: feed the attendance engine -----------------------------------------
        // Mirrors BiometricIngestController: the punch reaches attendance_records immediately
        // rather than waiting for the nightly schedule, so the employee sees today's status.
        ComputeAttendanceForDate::dispatch($checkIn->work_date->toDateString());

        return $checkIn;
    }

    /*
    |--------------------------------------------------------------------------
    | Gates
    |--------------------------------------------------------------------------
    */

    /**
     * Verify the WebAuthn assertion, honouring the WEBAUTHN_REQUIRE hard-block setting.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attempt
     */
    private function verifyDevice(User $user, array $payload, array $attempt, ?Request $request): ?UserDevice
    {
        $credential = $payload['credential'] ?? null;

        try {
            if (! is_array($credential) || $credential === []) {
                throw new RuntimeException('No assertion was supplied.');
            }

            return $this->webauthn->verifyAssertion($user, $credential, $request);
        } catch (RuntimeException) {
            if ($this->webauthn->isRequired()) {
                $this->refuse($attempt, CheckInRejection::WebauthnFailed);
            }

            // Only reachable with WEBAUTHN_REQUIRE=false, a deliberate downgrade for
            // environments without a secure context. The punch is still recorded, but
            // `webauthn_verified` stays false so the audit log shows exactly what was proven.
            return null;
        }
    }

    /**
     * Is now within the acceptable window around the employee's scheduled start (for a check
     * in) or end (for a check out)?
     *
     * The offsets live in config/attendance.php rather than as columns on `work_schedules`:
     * that table is shared with the biometric path and its calculator fixtures, so a mobile-only
     * tuning change would otherwise put the core attendance engine in the blast radius.
     *
     * @param  array<string, mixed>  $attempt
     */
    private function assertWithinScheduleWindow(Employee $employee, CheckInDirection $direction, array $attempt): void
    {
        $schedule = $employee->workSchedule;

        if ($schedule === null) {
            $this->refuse($attempt, CheckInRejection::NoWorkSchedule);
        }

        $now = $attempt['punched_at'];

        [$anchor, $earlyKey, $lateKey] = $direction === CheckInDirection::In
            ? [$schedule->start_time, 'check_in_early_minutes', 'check_in_late_minutes']
            : [$schedule->end_time, 'check_out_early_minutes', 'check_out_late_minutes'];

        $anchorTime = Carbon::parse($attempt['work_date'].' '.$anchor);

        $opens = $anchorTime->copy()->subMinutes((int) config("attendance.mobile.{$earlyKey}"));
        $closes = $anchorTime->copy()->addMinutes((int) config("attendance.mobile.{$lateKey}"));

        if ($now->lt($opens) || $now->gt($closes)) {
            $this->refuse($attempt, CheckInRejection::OutsideScheduleWindow);
        }
    }

    /**
     * Ordering, duplication, the punch row, and the check-in row — all under one lock.
     *
     * The lock is what makes "one check-in per day" true. Without it, two taps landing together
     * both read "no check-in yet" and both write one; there is deliberately no unique index to
     * catch that, because such an index would have to apply only to accepted rows and MySQL has
     * no partial indexes.
     *
     * @param  array<string, mixed>  $attempt
     */
    private function commit(Employee $employee, CheckInDirection $direction, array $attempt): MobileCheckIn
    {
        try {
            return DB::transaction(function () use ($employee, $direction, $attempt) {
                // whereDate, not a plain where: the `date` cast serializes work_date as
                // "Y-m-d 00:00:00", so an equality match against a bare date string silently
                // finds nothing — and silently finding nothing here means every duplicate
                // check-in is accepted.
                $today = MobileCheckIn::where('employee_id', $employee->id)
                    ->whereDate('work_date', $attempt['work_date'])
                    ->accepted()
                    ->lockForUpdate()
                    ->get();

                $checkedIn = $today->firstWhere('direction', CheckInDirection::In);
                $checkedOut = $today->firstWhere('direction', CheckInDirection::Out);

                // ---- Gate 7: ordering and duplication ------------------------------------
                if ($direction === CheckInDirection::In && $checkedIn !== null) {
                    $this->abort(CheckInRejection::AlreadyCheckedIn);
                }

                // "Have they arrived yet?" is a question about the day, not about this channel.
                // An employee who badged in at a terminal and then left for the field must be
                // able to check out from their phone — refusing that would make the two
                // channels compete instead of converging, which is the opposite of the design.
                // So any punch today counts, from either source.
                if ($direction === CheckInDirection::Out && $checkedIn === null && ! $this->hasArrived($employee, $attempt)) {
                    $this->abort(CheckInRejection::NoCheckIn);
                }

                if ($direction === CheckInDirection::Out && $checkedOut !== null) {
                    $this->abort(CheckInRejection::AlreadyCheckedOut);
                }

                // Debounce: a double tap, or a retry after a slow response, is not two punches.
                $last = $today->sortByDesc('punched_at')->first();
                $minInterval = (int) config('attendance.mobile.min_interval_seconds');

                if ($last !== null && $attempt['punched_at']->diffInSeconds($last->punched_at, true) < $minInterval) {
                    $this->abort(CheckInRejection::TooSoon);
                }

                // ---- Gate 8: impossible travel -------------------------------------------
                // Flagged, never rejected. An employee who genuinely flew, or whose previous
                // fix was simply bad, must not be locked out of their own attendance; an Admin
                // seeing the flag can judge it, an automatic refusal cannot.
                [$flagged, $flagReason] = $this->assessTravel($employee, $attempt);

                // ---- Gate 9: the punch itself --------------------------------------------
                $log = $this->writePunch($employee, $attempt);

                // ---- Gate 10: the check-in record ----------------------------------------
                return MobileCheckIn::create($attempt + [
                    'raw_attendance_log_id' => $log->id,
                    'result' => CheckInResult::Accepted,
                    'flagged' => $flagged,
                    'flag_reason' => $flagReason,
                ]);
            });
        } catch (MobileCheckInException $e) {
            // The transaction has rolled back, so a rejection row written inside it would have
            // vanished with it. Persist the refusal now that the transaction has unwound, then
            // surface the failure.
            $e->attempt = $this->persistRejection($attempt, $e->reason);

            throw $e;
        }
    }

    /**
     * Write the punch into `raw_attendance_logs`, where the two channels converge.
     *
     * `punched_at` is the SERVER's clock, never the client's. A client-supplied timestamp would
     * be a one-line late-arrival bypass. This deliberately differs from BiometricIngestController,
     * which does trust `punched_at` because that value comes from bio-service over an
     * internal-secret-authenticated call, not from a phone. Do not "harmonise" the two.
     *
     * @param  array<string, mixed>  $attempt
     */
    private function writePunch(Employee $employee, array $attempt): RawAttendanceLog
    {
        try {
            return RawAttendanceLog::create([
                // Sentinel values, not nulls: they keep the existing dedupe unique index working
                // for mobile rows and guarantee the enrollment lookup can never match one.
                'device_serial' => RawAttendanceLog::MOBILE_SERIAL,
                'device_user_id' => (string) $employee->id,
                'employee_id' => $employee->id,
                'source' => AttendanceSource::Mobile,
                'punched_at' => $attempt['punched_at'],
                'raw_payload' => [
                    'direction' => $attempt['direction']->value,
                    'latitude' => $attempt['latitude'],
                    'longitude' => $attempt['longitude'],
                    'accuracy_meters' => $attempt['accuracy_meters'],
                    'distance_meters' => $attempt['distance_meters'],
                ],
            ]);
        } catch (QueryException $e) {
            // 23000 is the integrity-constraint class: the dedupe index rejected this punch as
            // one already recorded to the second. That is a duplicate submission, not a server
            // fault, so it earns a friendly refusal rather than a 500.
            if ((string) $e->getCode() === '23000') {
                $this->abort(CheckInRejection::DuplicatePunch);
            }

            throw $e;
        }
    }

    /**
     * Has this employee any punch at all today, from either channel?
     *
     * Used only to answer "can they check out". A biometric punch is direction-agnostic — the
     * calculator takes first() and last() of the day — so its mere existence is what matters.
     *
     * @param  array<string, mixed>  $attempt
     */
    private function hasArrived(Employee $employee, array $attempt): bool
    {
        // Mobile rows carry employee_id directly.
        $mobile = RawAttendanceLog::where('employee_id', $employee->id)
            ->whereDate('punched_at', $attempt['work_date'])
            ->exists();

        if ($mobile) {
            return true;
        }

        // Device rows resolve through enrollments, and must be matched on the serial AND the
        // device's own user ID together — the same device_user_id on two different terminals
        // belongs to two different people.
        $enrollments = $employee->deviceEnrollments()->with('biometricDevice')->get();

        if ($enrollments->isEmpty()) {
            return false;
        }

        return RawAttendanceLog::whereDate('punched_at', $attempt['work_date'])
            ->where(function ($query) use ($enrollments) {
                foreach ($enrollments as $enrollment) {
                    $query->orWhere(fn ($q) => $q
                        ->where('device_serial', $enrollment->biometricDevice?->serial)
                        ->where('device_user_id', $enrollment->device_user_id));
                }
            })
            ->exists();
    }

    /**
     * Compare this fix against the employee's previous accepted one and decide whether the
     * implied speed is physically plausible.
     *
     * @param  array<string, mixed>  $attempt
     * @return array{0: bool, 1: string|null}
     */
    private function assessTravel(Employee $employee, array $attempt): array
    {
        $previous = MobileCheckIn::where('employee_id', $employee->id)
            ->accepted()
            ->whereNotNull('latitude')
            ->latest('punched_at')
            ->first();

        if ($previous === null) {
            return [false, null];
        }

        $seconds = $attempt['punched_at']->diffInSeconds($previous->punched_at, true);

        if ($seconds <= 0) {
            return [false, null];
        }

        $metres = $this->geofence->distanceMeters(
            (float) $previous->latitude,
            (float) $previous->longitude,
            $attempt['latitude'],
            $attempt['longitude'],
        );

        $kmh = ($metres / 1000) / ($seconds / 3600);
        $limit = (float) config('attendance.mobile.impossible_travel_kmh');

        if ($kmh <= $limit) {
            return [false, null];
        }

        return [true, sprintf(
            'Implied travel of %.0f km/h since the previous punch (%.0f m in %d s).',
            $kmh,
            $metres,
            $seconds,
        )];
    }

    /*
    |--------------------------------------------------------------------------
    | Rejection, audit, helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Persist the refusal, then throw. For gates running outside a transaction.
     *
     * @param  array<string, mixed>  $attempt
     *
     * @throws MobileCheckInException always
     */
    private function refuse(array $attempt, CheckInRejection $reason): void
    {
        throw new MobileCheckInException($reason, $this->persistRejection($attempt, $reason));
    }

    /**
     * Throw WITHOUT persisting — for gates inside `commit()`'s transaction, where the write
     * would be rolled back by the very throw meant to carry it. `commit()` catches this and
     * persists the rejection once the transaction has unwound.
     *
     * @throws MobileCheckInException always
     */
    private function abort(CheckInRejection $reason): void
    {
        throw new MobileCheckInException($reason);
    }

    /**
     * @param  array<string, mixed>  $attempt
     */
    private function persistRejection(array $attempt, CheckInRejection $reason): ?MobileCheckIn
    {
        // Gate 1 fires before an employee is known, and this table's FK requires one. Nothing
        // is lost: the controller still refuses, and an account with no employee has no
        // attendance history for the row to belong to.
        if (! isset($attempt['employee_id'])) {
            return null;
        }

        return MobileCheckIn::create($attempt + [
            'result' => CheckInResult::Rejected,
            'rejection_reason' => $reason,
        ]);
    }

    private function audit(User $user, MobileCheckIn $checkIn, WorkLocation $location): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'auditable_type' => MobileCheckIn::class,
            'auditable_id' => $checkIn->id,
            'action' => 'mobile.check-'.$checkIn->direction->value,
            'new_values' => [
                'work_date' => $checkIn->work_date->toDateString(),
                'punched_at' => $checkIn->punched_at->toDateTimeString(),
                'work_location' => $location->name,
                'latitude' => $checkIn->latitude,
                'longitude' => $checkIn->longitude,
                'accuracy_meters' => $checkIn->accuracy_meters,
                'distance_meters' => $checkIn->distance_meters,
                'webauthn_verified' => $checkIn->webauthn_verified,
                'flagged' => $checkIn->flagged,
                'ip' => $checkIn->ip_address,
            ],
            'note' => $checkIn->flag_reason,
        ]);

        if ($checkIn->flagged) {
            $this->notifyAdminsOfFlag($checkIn);
        }
    }

    private function notifyAdminsOfFlag(MobileCheckIn $checkIn): void
    {
        $admins = User::role(RoleName::Admin->value)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, SystemNotification::mobileCheckInFlagged(
            $checkIn->employee?->fullName() ?? 'An employee',
            $checkIn->flag_reason ?? 'Unusual movement between punches.',
            route('mobile-check-ins.index'),
        ));
    }

    /**
     * Coordinates arrive as JSON numbers or as numeric strings depending on the client;
     * normalise to float once, here, rather than casting defensively at every use.
     *
     * @param  array<string, mixed>  $payload
     */
    private function coordinate(array $payload, string $key): ?float
    {
        return isset($payload[$key]) && is_numeric($payload[$key]) ? (float) $payload[$key] : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Read models for the check-in page
    |--------------------------------------------------------------------------
    */

    /**
     * Today's accepted punches for an employee, as the check-in page needs them.
     *
     * @return array{checked_in_at: string|null, checked_out_at: string|null}
     */
    public function todayState(Employee $employee, ?CarbonInterface $date = null): array
    {
        $today = MobileCheckIn::where('employee_id', $employee->id)
            ->whereDate('work_date', ($date ?? now())->toDateString())
            ->accepted()
            ->get();

        return [
            'checked_in_at' => $today->firstWhere('direction', CheckInDirection::In)?->punched_at?->format('H:i'),
            'checked_out_at' => $today->firstWhere('direction', CheckInDirection::Out)?->punched_at?->format('H:i'),
        ];
    }

    /**
     * The window each direction is open, for display. Purely informational — the authoritative
     * check is assertWithinScheduleWindow(), which runs server-side on every attempt.
     *
     * @return array{in: array{opens: string, closes: string}, out: array{opens: string, closes: string}}|null
     */
    public function scheduleWindows(Employee $employee): ?array
    {
        $schedule = $employee->workSchedule;

        if ($schedule === null) {
            return null;
        }

        $date = now()->toDateString();

        $window = fn (string $anchor, string $earlyKey, string $lateKey) => [
            'opens' => Carbon::parse($date.' '.$anchor)
                ->subMinutes((int) config("attendance.mobile.{$earlyKey}"))->format('H:i'),
            'closes' => Carbon::parse($date.' '.$anchor)
                ->addMinutes((int) config("attendance.mobile.{$lateKey}"))->format('H:i'),
        ];

        return [
            'in' => $window($schedule->start_time, 'check_in_early_minutes', 'check_in_late_minutes'),
            'out' => $window($schedule->end_time, 'check_out_early_minutes', 'check_out_late_minutes'),
        ];
    }
}
