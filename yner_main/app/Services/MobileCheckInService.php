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
use Illuminate\Support\Str;
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
 * ONE ACCOUNT, ONE DEVICE. Gates 2 and 2b together answer "is this the one device linked to
 * this account?". The assertion is the primary proof and is never optional — no valid assertion
 * means no check-in, full stop. The binding token is corroboration: it identifies the browser
 * profile that was enrolled, which the credential alone cannot.
 *
 * The two are weighted differently on purpose. A WRONG credential is refused outright. A MISSING
 * token is not, because nothing on the web identifies physical hardware — a cookie and its
 * localStorage mirror are per-browser-profile, so a second browser, an in-app webview, or
 * cleared site data all look identical to a stolen handset. Those are refused only for the
 * account they belong to; for the account's own bound credential the punch is accepted, the
 * token is re-issued to that browser, and the takeover is flagged and audited. See
 * assertBoundDevice() for why, and DeviceResetRequest for the one path that re-points an account
 * at a genuinely different phone.
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
        private readonly DeviceTokenService $deviceTokens,
        private readonly DeviceFormFactorDetector $formFactor,
    ) {}

    /** Set by a re-claim during record(); read by the controller to refresh the client mirror. */
    private ?string $reissuedToken = null;

    /**
     * Run one check-in or check-out attempt end to end.
     *
     * @param  array<string, mixed>  $payload  latitude, longitude, accuracy_meters, credential
     *
     * @throws MobileCheckInException on any refusal — the attempt is persisted first
     */
    public function record(User $user, CheckInDirection $direction, array $payload, ?Request $request = null): MobileCheckIn
    {
        $this->reissuedToken = null;

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

        // ---- Gate 1b: a phone or tablet --------------------------------------------------
        // This channel is for handsets. A punch from a laptop or desktop is refused before the
        // WebAuthn prompt, because the machine's "location" is Wi-Fi triangulation from the
        // office router and its authenticator is shared with everyone who can unlock it —
        // neither of which the gates below can tell apart from the real thing.
        //
        // Placed AFTER the employee lookup so the refusal is recorded against them: an
        // employee repeatedly trying from a desktop is something an Admin should be able to
        // see in the log, not something that vanishes with a bare 422.
        if ($this->formFactor->refuses($request)) {
            $this->refuse($attempt, CheckInRejection::UnsupportedDevice);
        }

        // ---- Gate 2: WebAuthn assertion --------------------------------------------------
        // Verified against the challenge this server put in the session, which the service
        // deletes as it reads it. A client claim of "my device is verified" is never accepted,
        // and there is no downgrade path: no valid assertion means no check-in, full stop.
        $device = $this->verifyDevice($user, $payload, $attempt, $request);

        $attempt['user_device_id'] = $device->id;
        $attempt['webauthn_verified'] = true;

        // ---- Gate 2b: the device binding -------------------------------------------------
        // An account is linked to one and only one device, and only that device may punch for
        // it. The assertion above proves a registered credential signed; this proves it was
        // THE credential — and that the handset presenting it is the same handset that was
        // enrolled, which the credential alone cannot establish.
        $this->assertBoundDevice($user, $device, $attempt, $request);

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
     * Verify the WebAuthn assertion. Unconditional — there is no unverified path.
     *
     * There used to be a WEBAUTHN_REQUIRE flag allowing a degraded mode in which an unverified
     * request still produced a punch. It was removed rather than defaulted-on, because under
     * the one-account-one-device rule that mode is a contradiction: "which device is this" is
     * the entire question this channel asks, and an attendance control that answers "we could
     * not tell" and records the punch anyway is not a control.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attempt
     */
    private function verifyDevice(User $user, array $payload, array $attempt, ?Request $request): UserDevice
    {
        $credential = $payload['credential'] ?? null;

        try {
            if (! is_array($credential) || $credential === []) {
                throw new RuntimeException('No assertion was supplied.');
            }

            return $this->webauthn->verifyAssertion($user, $credential, $request);
        } catch (RuntimeException) {
            $this->refuse($attempt, CheckInRejection::WebauthnFailed);
        }
    }

    /**
     * The asserted credential must be the account's one bound device, presented by the handset
     * that actually holds it.
     *
     * Three separate things are checked, and they fail for genuinely different reasons:
     *
     *   1. NO BINDING AT ALL. The account has no active device — nothing to check in from.
     *      Distinguished from a mismatch so the UI can offer registration rather than a reset.
     *
     *   2. WRONG CREDENTIAL. The assertion verified, and belongs to this user, but is not the
     *      credential holding the binding slot. In practice this means a credential that
     *      survived on a phone whose binding was later moved elsewhere.
     *
     *   3. WRONG HANDSET. The credential is right but the device token is missing or belongs to
     *      a different row. This is the case WebAuthn cannot see: a passkey exported or synced
     *      to a second handset still produces a valid assertion for the same credential ID, and
     *      only a secret pinned to the original device notices.
     *
     * All three are recorded as rejections AND flagged, because each of them is a plausible
     * account-sharing attempt rather than an ordinary user error, and an Admin should be told.
     *
     * @param  array<string, mixed>  $attempt
     */
    private function assertBoundDevice(User $user, UserDevice $device, array &$attempt, ?Request $request): void
    {
        $bound = UserDevice::boundTo($user->id);

        if ($bound === null) {
            $this->refuse($attempt, CheckInRejection::NoLinkedDevice);
        }

        // The asserted credential is not the one holding this account's binding slot. A hard
        // refusal: the passkey itself is wrong, and re-issuing a token cannot fix that.
        if ($bound->id !== $device->id) {
            $this->refuse($attempt, CheckInRejection::DeviceMismatch, flagged: true,
                flagReason: 'Assertion came from a credential that is not the account\'s linked device.');
        }

        $presented = $this->deviceTokens->resolve($this->deviceTokens->presented($request));

        if ($presented !== null && $presented->id === $bound->id) {
            return;
        }

        // A token belonging to somebody else's active device. This browser was previously
        // enrolled against a different account, which is the account-sharing signal the whole
        // binding exists to catch — refuse, and do not re-issue.
        if ($presented !== null) {
            $this->refuse($attempt, CheckInRejection::DeviceMismatch, flagged: true,
                flagReason: 'The device binding token belongs to a different account\'s device.');
        }

        // RE-CLAIM. No token — but the account's own bound credential just signed a fresh
        // server challenge behind a biometric prompt, which is a strong proof. And a missing
        // token has an ordinary explanation far more often than a sinister one: the cookie and
        // its localStorage mirror are per-BROWSER-PROFILE, not per-device, because nothing on
        // the web identifies physical hardware. A second browser, an in-app webview (a link
        // opened from WhatsApp or Gmail), or cleared site data all present as "no token" for
        // the same person on the same phone.
        //
        // Refusing would lock those people out of their own attendance and route a browser
        // switch through an Admin-approved device reset. So this follows the rule the
        // impossible-travel gate already sets in this file: flag, do not reject — an Admin
        // seeing the flag can judge it, an automatic refusal cannot.
        //
        // Issuing overwrites `device_token_hash`, so the previously trusted browser's token
        // stops resolving: exactly one browser holds a live token at a time. Two people taking
        // turns on one account therefore generate a visible ping-pong of re-claims in the
        // check-in log rather than sharing quietly.
        $this->reclaim($bound, $attempt);
    }

    /**
     * Hand this browser a fresh binding token and record that it took over.
     *
     * @param  array<string, mixed>  $attempt
     */
    private function reclaim(UserDevice $bound, array &$attempt): void
    {
        $this->reissuedToken = $this->deviceTokens->issue($bound);
        $this->deviceTokens->queueCookie($this->reissuedToken);

        $attempt['flagged'] = true;
        $attempt['flag_reason'] = 'Device binding re-claimed by a browser holding no token ('
            .Str::limit((string) ($attempt['user_agent'] ?? 'unknown client'), 80, '').').';

        AuditLog::create([
            'user_id' => $bound->user_id,
            'auditable_type' => UserDevice::class,
            'auditable_id' => $bound->id,
            'action' => 'device.token_reclaimed',
            'new_values' => [
                'device_name' => $bound->device_name,
                'user_agent' => $attempt['user_agent'] ?? null,
                'ip' => $attempt['ip_address'] ?? null,
            ],
        ]);
    }

    /**
     * The binding token minted during this request, if the handset re-claimed one.
     *
     * Per-request state on the service, which is not lovely, but the alternative is threading a
     * second return value through `record()` and every gate under it. The queued cookie alone
     * would almost do — this exists so the client can also refresh its localStorage mirror,
     * without which a browser whose cookies expire would re-claim, and flag, all over again.
     */
    public function reissuedDeviceToken(): ?string
    {
        return $this->reissuedToken;
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

                // Merged, not replaced: gate 2b may already have flagged this attempt for a
                // token re-claim, and one punch can be both re-claimed and implausibly
                // travelled. Overwriting here would hide whichever came first.
                if ($flagged) {
                    $attempt['flagged'] = true;
                    $attempt['flag_reason'] = trim((string) ($attempt['flag_reason'] ?? '').' '.$flagReason);
                }

                // ---- Gate 9: the punch itself --------------------------------------------
                $log = $this->writePunch($employee, $attempt);

                // ---- Gate 10: the check-in record ----------------------------------------
                // `$attempt` wins the union, so anything gate 2b set is preserved and these
                // are the defaults for the ordinary unflagged case.
                return MobileCheckIn::create($attempt + [
                    'raw_attendance_log_id' => $log->id,
                    'result' => CheckInResult::Accepted,
                    'flagged' => false,
                    'flag_reason' => null,
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
     * `$flagged` is for refusals that are not merely a user getting something wrong — a device
     * mismatch is a plausible account-sharing attempt, and an Admin should hear about it the
     * same way they hear about a flagged acceptance. Ordinary refusals (bad GPS, wrong hour)
     * stay unflagged; flagging everything would make the flag mean nothing.
     *
     * @param  array<string, mixed>  $attempt
     *
     * @throws MobileCheckInException always
     */
    private function refuse(array $attempt, CheckInRejection $reason, bool $flagged = false, ?string $flagReason = null): never
    {
        $row = $this->persistRejection($attempt, $reason, $flagged, $flagReason);

        if ($flagged && $row !== null) {
            $this->notifyAdminsOfFlag($row);
        }

        throw new MobileCheckInException($reason, $row);
    }

    /**
     * Throw WITHOUT persisting — for gates inside `commit()`'s transaction, where the write
     * would be rolled back by the very throw meant to carry it. `commit()` catches this and
     * persists the rejection once the transaction has unwound.
     *
     * @throws MobileCheckInException always
     */
    private function abort(CheckInRejection $reason): never
    {
        throw new MobileCheckInException($reason);
    }

    /**
     * @param  array<string, mixed>  $attempt
     */
    private function persistRejection(array $attempt, CheckInRejection $reason, bool $flagged = false, ?string $flagReason = null): ?MobileCheckIn
    {
        // Gate 1 fires before an employee is known, and this table's FK requires one. Nothing
        // is lost: the controller still refuses, and an account with no employee has no
        // attendance history for the row to belong to.
        if (! isset($attempt['employee_id'])) {
            return null;
        }

        // array_merge, not `+`: an explicitly flagged refusal must override whatever gate 2b
        // left on the attempt, rather than being silently discarded by it.
        return MobileCheckIn::create(array_merge($attempt, [
            'result' => CheckInResult::Rejected,
            'rejection_reason' => $reason,
            'flagged' => $flagged || (bool) ($attempt['flagged'] ?? false),
            'flag_reason' => $flagReason ?? ($attempt['flag_reason'] ?? null),
        ]));
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
