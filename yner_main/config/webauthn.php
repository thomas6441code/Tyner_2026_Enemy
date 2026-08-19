<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party
    |--------------------------------------------------------------------------
    |
    | The RP ID MUST be the public, registrable domain the browser sees — no scheme, no port,
    | no container hostname. `eapms.example.ac.tz`, never `http://eapms.example.ac.tz:8000`
    | and never `localhost` in production.
    |
    | Credentials are cryptographically bound to this value. Change it and every existing
    | passkey stops verifying — which is why `user_devices.rp_id` records the value each
    | credential was created under, so a domain move produces an honest "re-register your
    | device" prompt instead of an opaque signature failure.
    |
    | Leaving this null falls back to the host of APP_URL, which is right for local work and
    | wrong for anything deployed behind a proxy.
    |
    */

    'rp_id' => env('WEBAUTHN_RP_ID'),

    'rp_name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'EAPMS')),

    /*
    |--------------------------------------------------------------------------
    | Ceremony timeout
    |--------------------------------------------------------------------------
    |
    | Milliseconds the browser gives the user to present their fingerprint or face before
    | aborting with NotAllowedError. Long enough that a fumbled first attempt is recoverable.
    |
    */

    'timeout_ms' => (int) env('WEBAUTHN_TIMEOUT_MS', 60_000),

    /*
    |--------------------------------------------------------------------------
    | Exclude-credentials cap
    |--------------------------------------------------------------------------
    |
    | Registration sends every active credential in the system as `excludeCredentials`, so a
    | phone that already holds one refuses to mint a second for another account. That list is
    | the hardware half of the one-account-one-device rule and it grows with headcount, so it
    | is capped: authenticators have their own practical limits, and the request is not free.
    |
    | The list is ordered most-recently-used first, so if the cap ever bites, the credentials
    | actually in circulation are the ones covered. Raise this above the number of active
    | devices you expect; a warning is logged whenever it truncates.
    |
    */

    'exclude_limit' => (int) env('WEBAUTHN_EXCLUDE_LIMIT', 500),

    /*
    |--------------------------------------------------------------------------
    | Session keys
    |--------------------------------------------------------------------------
    |
    | Challenges live in the session, never in a hidden form field. A challenge the client
    | can choose is not a challenge.
    |
    */

    'session' => [
        'registration' => 'webauthn.registration_options',
        'assertion' => 'webauthn.assertion_options',
    ],

];
