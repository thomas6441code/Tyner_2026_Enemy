/**
 * The handset's device binding token, mirrored in localStorage.
 *
 * The server issues this once, at registration, and stores only its hash. It travels back on
 * every check-in as proof that the request came from the same physical phone that was enrolled
 * — something the WebAuthn credential alone cannot establish, because a synced or exported
 * passkey produces a perfectly valid assertion from a second device.
 *
 * WHY TWO STORES. The server also sets the same value as an httpOnly cookie. Browsers clear
 * cookies and localStorage independently — iOS Safari's 7-day cap on script-written storage, a
 * user clearing "cookies and site data", a private window promoted to a normal one — so a phone
 * that lost one copy should not be treated as a different phone. The header below is the
 * fallback the cookie cannot provide, and vice versa.
 *
 * WHY THIS IS SAFE TO KEEP IN LOCALSTORAGE. The token is not a credential: on its own it
 * authenticates nothing, because check-in also requires a WebAuthn assertion from the linked
 * device and a live session. It answers "which handset", not "which person".
 *
 * Note what is NOT used to answer that question: the client's IP address. Every phone on the
 * office Wi-Fi shares one, so it would pass for a colleague standing beside you and fail for you
 * on mobile data.
 */

const STORAGE_KEY = 'eapms.device_token';

/** Must match config('device.token_header'). */
export const DEVICE_TOKEN_HEADER = 'X-Device-Token';

/**
 * Private browsing and hardened privacy settings can make localStorage throw on access rather
 * than simply return null, so every touch is guarded. Losing the mirror is recoverable — the
 * cookie still carries the token — while an exception here would break the whole check-in page.
 */
export function readDeviceToken(): string | null {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

export function storeDeviceToken(token: string): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, token);
    } catch {
        // No mirror; the httpOnly cookie remains the primary carrier.
    }
}

export function clearDeviceToken(): void {
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing to do — the server ignores tokens that no longer resolve to an active device.
    }
}

/**
 * The header to merge into a fetch, or nothing when this handset has no mirrored token.
 */
export function deviceTokenHeader(): Record<string, string> {
    const token = readDeviceToken();

    return token ? { [DEVICE_TOKEN_HEADER]: token } : {};
}
