<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Device binding token
    |--------------------------------------------------------------------------
    |
    | One account is linked to one and only one device. Three independent layers enforce it:
    | the authenticator itself (WebAuthn excludeCredentials, see config/webauthn.php), the
    | database (user_devices.active_user_id / device_token_hash unique indexes), and this
    | token — a random secret minted at registration, stored hashed, and presented on every
    | check-in.
    |
    | The token exists because the other two layers each have a gap: a passkey can be deleted
    | from the phone, and a unique index cannot tell two handsets apart. It is delivered both
    | as a signed httpOnly cookie and as a localStorage mirror echoed back in the
    | X-Device-Token header, because browsers clear those two stores independently.
    |
    | Note what is deliberately NOT here: the client's IP address. Everyone on the office
    | Wi-Fi shares one, so it identifies nothing. IPs are recorded for audit and never read
    | to make a decision.
    |
    */

    'token_cookie' => env('DEVICE_TOKEN_COOKIE', 'eapms_device'),

    'token_header' => 'X-Device-Token',

    // Two years. The cookie should outlive the phone, not the session — an expiry that bites
    // would silently downgrade every check-in to "token missing" and lock people out.
    'token_ttl_days' => (int) env('DEVICE_TOKEN_TTL_DAYS', 730),

    /*
    |--------------------------------------------------------------------------
    | Device reset window
    |--------------------------------------------------------------------------
    |
    | Employees cannot swap their own device — that would make the binding self-service and
    | therefore worthless. They request a reset; an Admin or HR Officer approves it, which
    | revokes the old device and opens a one-time window for re-registration.
    |
    | The window is short on purpose: an approval left open indefinitely is a standing licence
    | to enroll whatever handset happens to be nearby.
    |
    */

    'reset_window_hours' => (int) env('DEVICE_RESET_WINDOW_HOURS', 24),

];
