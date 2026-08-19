<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Employee code
    |--------------------------------------------------------------------------
    |
    | Employee codes are allocated by the server, never typed by a human. They are the join
    | key between an HR record and its biometric enrolments, they appear on exported reports,
    | and they are unique — all three are reasons a typo is expensive and a duplicate is worse.
    |
    | The sequence is derived from the highest existing code carrying this prefix, so codes
    | outside it (imported or legacy formats) are ignored rather than colliding. Changing the
    | prefix therefore starts a fresh sequence rather than renumbering anyone; existing codes
    | are never rewritten, because device enrolments and historical reports refer to them.
    |
    | `padding` is the zero-padded width of the numeric part. Widen it before you approach the
    | limit, not after: EMP-9999 followed by EMP-10000 still sorts and increments correctly,
    | but the column stops being fixed-width.
    |
    */

    'code_prefix' => env('EMPLOYEE_CODE_PREFIX', 'EMP-'),

    'code_padding' => (int) env('EMPLOYEE_CODE_PADDING', 4),

];
