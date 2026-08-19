<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The device binding token: a secret this server minted, stored on the handset, presented back
 * on every check-in.
 *
 * WHY THIS EXISTS ALONGSIDE WEBAUTHN. The passkey already proves which credential signed. What
 * it cannot do is survive its own deletion: a user who removes the passkey from their phone,
 * then enrolls a fresh one on the same handset for a colleague's account, has produced two
 * credentials the authenticator no longer relates to each other. The token is the second
 * thread — it is not tied to the credential, so it still says "this handset is already spoken
 * for".
 *
 * WHAT IT DOES NOT DO. Clearing cookies and localStorage clears the token. That is accepted,
 * not overlooked. The three layers are deliberately different in kind:
 *
 *   - the authenticator's excludeCredentials check cannot be cleared without deleting the
 *     passkey, which breaks the user's own check-in;
 *   - this token cannot be forged, only discarded, and discarding it fails check-in loudly;
 *   - the unique indexes cannot be worked around at all.
 *
 * Together they make sharing an account expensive and noisy. Any one of them alone would not.
 *
 * WHAT IS DELIBERATELY NOT USED: the client IP. Every phone on the office Wi-Fi presents the
 * same address, so an IP check would pass for a colleague standing beside you and fail for you
 * on mobile data. IPs are recorded for audit and never consulted for a decision.
 */
class DeviceTokenService
{
    /**
     * Mint a token for a device and store only its digest.
     *
     * 32 bytes from the CSPRNG. The plaintext is returned exactly once, to the client that just
     * completed registration; it is never recoverable from the row afterwards, which is why the
     * column is a hash and not the value.
     */
    public function issue(UserDevice $device): string
    {
        $token = $this->encode(random_bytes(32));

        $device->forceFill([
            'device_token_hash' => $this->hash($token),
            'device_token_issued_at' => now(),
        ])->save();

        return $token;
    }

    /**
     * The token the client presented, if any.
     *
     * Two carriers, because browsers clear the two stores independently and a phone that has
     * lost only one of them should not be treated as a new handset:
     *
     *   1. a signed httpOnly cookie — invisible to script, so a stray XSS cannot read it;
     *   2. the X-Device-Token header, echoed by our own JS from localStorage.
     *
     * Neither is trusted more than the other, because neither needs to be: the value is
     * compared against a stored digest, so a forged one simply fails to resolve.
     */
    public function presented(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $cookie = $request->cookie(config('device.token_cookie'));

        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        $header = $request->header(config('device.token_header'));

        return is_string($header) && $header !== '' ? $header : null;
    }

    /**
     * Resolve a presented token to the active device it belongs to.
     *
     * Revoked devices are excluded here rather than at the call sites: a token belonging to a
     * retired phone must behave exactly like a token belonging to no phone.
     */
    public function resolve(?string $token): ?UserDevice
    {
        if ($token === null || $token === '') {
            return null;
        }

        return UserDevice::query()
            ->where('status', DeviceStatus::Active)
            ->where('device_token_hash', $this->hash($token))
            ->first();
    }

    /**
     * Queue the long-lived cookie carrying the token.
     *
     * httpOnly so script cannot read it, sameSite=lax so it still rides along on the top-level
     * navigation to the check-in page, and secure in every environment that is not local —
     * WebAuthn already requires a secure context, so this costs nothing in practice.
     */
    public function queueCookie(string $token): void
    {
        Cookie::queue(Cookie::make(
            name: config('device.token_cookie'),
            value: $token,
            minutes: (int) config('device.token_ttl_days') * 24 * 60,
            path: '/',
            domain: null,
            secure: ! app()->environment('local'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    public function forgetCookie(): void
    {
        Cookie::queue(Cookie::forget(config('device.token_cookie')));
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
