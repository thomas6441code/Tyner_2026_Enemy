/**
 * The one thing the client can tell the server about its own shape that a User-Agent cannot.
 *
 * Attendance is a phone-and-tablet channel; the server decides that from the User-Agent and
 * Chromium's `Sec-CH-UA-Mobile` client hint (see App\Services\DeviceFormFactorDetector). Those
 * two cover everything except one case: iPadOS ships with "Request Desktop Website" on by
 * default and sends a Macintosh User-Agent, so an iPad is otherwise indistinguishable from an
 * iMac. `maxTouchPoints` separates them — a touch-screen iPad reports 5, a Mac with a
 * multi-touch trackpad reports 0.
 *
 * This is a hint, never permission: the server only ever uses it to let a *Macintosh* UA
 * through as a tablet, and every other gate in the check-in chain runs regardless of it.
 */

/** Must match DeviceFormFactorDetector::TOUCH_POINTS_HEADER. */
export const TOUCH_POINTS_HEADER = 'X-Client-Touch-Points';

export function touchPointsHeader(): Record<string, string> {
    const points = typeof navigator !== 'undefined' ? navigator.maxTouchPoints : undefined;

    return typeof points === 'number' ? { [TOUCH_POINTS_HEADER]: String(points) } : {};
}
