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
    | Hard requirement
    |--------------------------------------------------------------------------
    |
    | When true (the default and the intended posture), the server refuses any mobile
    | check-in that does not carry a valid assertion. There is deliberately no graceful
    | degradation path: an attendance channel that falls back to "trust the browser" when
    | WebAuthn is unavailable is not an attendance control at all.
    |
    | Set false only in a local environment where you are testing the surrounding flow
    | without a platform authenticator.
    |
    */

    'require' => (bool) env('WEBAUTHN_REQUIRE', true),

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
