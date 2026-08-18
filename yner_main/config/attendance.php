<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile check-in channel
    |--------------------------------------------------------------------------
    |
    | Tunables for the WebAuthn + geofence mobile attendance channel. These live in config
    | rather than as columns on `work_schedules` on purpose: that table is shared with the
    | biometric path and its AttendanceCalculatorTest fixtures, so adding columns there would
    | put the core attendance engine in the blast radius of a mobile-only tuning change.
    |
    */

    'mobile' => [

        // Reject any fix coarser than this. Blocks IP- and cell-tower-derived positions, which
        // can be kilometres out and would sail through a 150m geofence by luck.
        'max_accuracy_meters' => (int) env('MOBILE_MAX_ACCURACY_METERS', 100),

        // Minimum gap between two accepted punches for one employee. Debounces double-taps.
        'min_interval_seconds' => (int) env('MOBILE_MIN_INTERVAL_SECONDS', 60),

        // How far either side of the schedule's start time a check-in is accepted.
        'check_in_early_minutes' => (int) env('MOBILE_CHECK_IN_EARLY_MINUTES', 120),
        'check_in_late_minutes' => (int) env('MOBILE_CHECK_IN_LATE_MINUTES', 240),

        // Likewise around the schedule's end time for check-out.
        'check_out_early_minutes' => (int) env('MOBILE_CHECK_OUT_EARLY_MINUTES', 120),
        'check_out_late_minutes' => (int) env('MOBILE_CHECK_OUT_LATE_MINUTES', 240),

        // Implied speed above which a punch is flagged (not rejected) as impossible travel.
        // Rejecting would lock out an employee who genuinely flew, or whose previous fix was
        // simply bad; flagging surfaces it to Admin without breaking their day.
        'impossible_travel_kmh' => (int) env('MOBILE_IMPOSSIBLE_TRAVEL_KMH', 200),
    ],

];
