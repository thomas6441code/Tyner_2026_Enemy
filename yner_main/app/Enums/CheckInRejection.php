<?php

namespace App\Enums;

/**
 * Why a mobile check-in was refused.
 *
 * The gate chain in MobileCheckInService is strict, ordered and fail-closed, and each gate has
 * exactly one reason here. Keeping them as an enum rather than free strings means the admin log
 * can filter on them, the UI can map each to actionable copy, and a test can assert one case
 * per reason without matching on prose that will drift.
 */
enum CheckInRejection: string
{
    case NoEmployeeRecord = 'no_employee_record';
    case WebauthnFailed = 'webauthn_failed';
    case NoLinkedDevice = 'no_linked_device';
    case DeviceMismatch = 'device_mismatch';
    case LowGpsAccuracy = 'low_gps_accuracy';
    case NoWorkLocation = 'no_work_location';
    case OutsideGeofence = 'outside_geofence';
    case NoWorkSchedule = 'no_work_schedule';
    case OutsideScheduleWindow = 'outside_schedule_window';
    case AlreadyCheckedIn = 'already_checked_in';
    case NoCheckIn = 'no_check_in';
    case AlreadyCheckedOut = 'already_checked_out';
    case TooSoon = 'too_soon';
    case DuplicatePunch = 'duplicate_punch';

    /**
     * Message shown to the employee. Each says what actually happened and, where the employee
     * can do something about it, what to do — "outside the geofence" with no distance is a
     * support call, "you are 400m away" is self-service.
     */
    public function message(): string
    {
        return match ($this) {
            self::NoEmployeeRecord => 'Your account is not linked to an active employee record. Contact HR.',
            self::WebauthnFailed => 'Your device could not be verified. Register this phone under My Device, then try again.',
            self::NoLinkedDevice => 'No device is linked to your account. Register this phone under My Device, then try again.',
            self::DeviceMismatch => 'This device is linked to a different account. Check in from your own registered phone, or ask an administrator for a device reset.',
            self::LowGpsAccuracy => 'Your location is not accurate enough. Move outdoors or somewhere with a clearer sky view and try again.',
            self::NoWorkLocation => 'No work location has been assigned to you or your department, so your position cannot be checked. Contact HR.',
            self::OutsideGeofence => 'You are outside your assigned work location.',
            self::NoWorkSchedule => 'No work schedule has been assigned to you, so there is no window to check in against. Contact HR.',
            self::OutsideScheduleWindow => 'This is outside your scheduled hours.',
            self::AlreadyCheckedIn => 'You have already checked in today.',
            self::NoCheckIn => 'You have not checked in today, so there is nothing to check out from.',
            self::AlreadyCheckedOut => 'You have already checked out today.',
            self::TooSoon => 'That was too soon after your last punch. Wait a moment and try again.',
            self::DuplicatePunch => 'This punch has already been recorded.',
        };
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
