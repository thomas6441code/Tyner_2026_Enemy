<?php

namespace App\Services;

use App\Enums\DeviceFormFactor;
use Illuminate\Http\Request;

/**
 * Decides whether a request came from a phone or tablet, or from a computer.
 *
 * WHAT THIS IS AND IS NOT. This is a policy gate, not a cryptographic one. Nothing a browser
 * sends about its own hardware is trustworthy: a User-Agent is one devtools toggle away from
 * saying whatever its owner wants. So this stops the ordinary case — an employee clocking in
 * from the desktop in the office, or from a laptop at home — and it is deliberately NOT the
 * thing standing between an attacker and a forged punch. That job belongs to the gates that
 * cannot be talked out of their answer: the WebAuthn assertion, the device binding token, and
 * the server-computed geofence distance. Those still run, unchanged, behind this one.
 *
 * ORDER OF EVIDENCE, strongest first:
 *
 *  1. `Sec-CH-UA-Mobile` — a structured client hint, sent by Chromium browsers on every
 *     request without being asked for. `?1` means the browser itself says it is on a phone.
 *  2. The User-Agent string — the only signal Safari and Firefox give us.
 *  3. `X-Client-Touch-Points`, set by our own JavaScript from `navigator.maxTouchPoints`.
 *     This exists for exactly one case: iPadOS defaults to "Request Desktop Website" and sends
 *     a Macintosh User-Agent, so an iPad is otherwise indistinguishable from an iMac. A Mac
 *     with a multi-touch trackpad reports 0 here, a touch-screen iPad reports 5.
 *
 * That last rule is a hole a determined user could climb through by setting one header from a
 * laptop. It is accepted knowingly: the alternative is refusing every iPad, and per the first
 * paragraph this gate is not where forgery is stopped anyway.
 */
class DeviceFormFactorDetector
{
    /**
     * Set by resources/js/lib/device-form-factor.ts. See the class docblock for why.
     */
    public const TOUCH_POINTS_HEADER = 'X-Client-Touch-Points';

    /**
     * Phones. `Mobi` is the token every mobile browser is contractually expected to carry —
     * it matches both "Mobi" (Firefox) and "Mobile" (Chrome, Safari) with one pattern.
     */
    private const PHONE = '/(iPhone|iPod|Windows Phone|IEMobile|BlackBerry|BB10|Opera Mini|Opera Mobi|webOS|Mobi)/i';

    /**
     * Tablets that say so. Checked BEFORE the phone pattern, because an iPad's User-Agent
     * carries `Mobile/15E148` and would otherwise read as a phone.
     */
    private const TABLET = '/(iPad|Tablet|PlayBook|Silk|Kindle|Nexus (?:7|9|10)|SM-T)/i';

    public function classify(?Request $request): DeviceFormFactor
    {
        if ($request === null) {
            return DeviceFormFactor::Unknown;
        }

        $agent = (string) $request->userAgent();

        // A client hint of `?1` is the browser asserting "phone" in a field that exists for no
        // other purpose, so it outranks any reading of the UA string.
        if ($this->clientHintMobile($request) === true) {
            return DeviceFormFactor::Phone;
        }

        if ($agent === '') {
            return DeviceFormFactor::Unknown;
        }

        if (preg_match(self::TABLET, $agent) === 1) {
            return DeviceFormFactor::Tablet;
        }

        if (preg_match(self::PHONE, $agent) === 1) {
            return DeviceFormFactor::Phone;
        }

        // Anything still calling itself Android after the two patterns above is a tablet: per
        // Google's own guidance, an Android device that omits the `Mobile` token is one.
        if (preg_match('/\bAndroid\b/i', $agent) === 1) {
            return DeviceFormFactor::Tablet;
        }

        // The iPadOS desktop-mode rescue. Narrowed to Macintosh UAs on purpose — a Windows or
        // Linux machine with a touch screen is still a computer, and there is no iPad hiding
        // behind those strings.
        if ($this->touchPoints($request) >= 2 && str_contains($agent, 'Macintosh')) {
            return DeviceFormFactor::Tablet;
        }

        // Recognisably a computer, versus a UA we simply cannot place (a script, a scanner, a
        // stripped-down browser). Both are refused; they are kept apart so the rejection log
        // and the audit trail say which one happened.
        if (preg_match('/(Windows NT|Macintosh|X11|CrOS|Linux x86)/i', $agent) === 1) {
            return DeviceFormFactor::Desktop;
        }

        return DeviceFormFactor::Unknown;
    }

    /**
     * True when this request must be refused because it did not come from a handheld.
     *
     * Honours the `attendance.mobile.require_handheld` switch, which exists so a deployment
     * running a pilot on shared office computers, or a test suite, can turn the rule off in
     * one place rather than by faking User-Agents everywhere.
     */
    public function refuses(?Request $request): bool
    {
        if (! config('attendance.mobile.require_handheld')) {
            return false;
        }

        return ! $this->classify($request)->isHandheld();
    }

    /**
     * `Sec-CH-UA-Mobile: ?1` / `?0`, or null when the browser does not send the hint at all.
     */
    private function clientHintMobile(Request $request): ?bool
    {
        $hint = $request->header('Sec-CH-UA-Mobile');

        return match (is_string($hint) ? trim($hint) : null) {
            '?1' => true,
            '?0' => false,
            default => null,
        };
    }

    private function touchPoints(Request $request): int
    {
        $value = $request->header(self::TOUCH_POINTS_HEADER);

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The message shown wherever this gate refuses — the same words on the check-in page, in
     * the registration dialog and in the rejection log, so an employee reading one and an
     * Admin reading another are looking at the same rule.
     */
    public function refusalMessage(): string
    {
        return 'Attendance can only be used from a phone or tablet. Open this page on your mobile device.';
    }
}
