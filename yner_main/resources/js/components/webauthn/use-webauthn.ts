import { startAuthentication, startRegistration } from '@simplewebauthn/browser';
import { useCallback, useEffect, useState } from 'react';
import { route } from 'ziggy-js';

import { deviceTokenHeader, storeDeviceToken } from '@/lib/device-token';

/**
 * Why @simplewebauthn/browser rather than calling navigator.credentials directly: every field
 * that crosses the wire is an ArrayBuffer on the client and base64url on the server. Hand-written
 * conversions between the two are the single largest source of WebAuthn bugs, and they fail with
 * opaque errors. This library emits exactly the JSON shape the PHP side's loader consumes.
 */

export type WebAuthnSupport = 'checking' | 'supported' | 'insecure-context' | 'unsupported';

/**
 * navigator.credentials is `undefined` outside a secure context — not an error you can catch,
 * the property simply is not there. So the capability probe has to check for the API's
 * existence before it can check for hardware.
 */
export function useWebAuthnSupport(): WebAuthnSupport {
    const [support, setSupport] = useState<WebAuthnSupport>('checking');

    useEffect(() => {
        let cancelled = false;

        const probe = async () => {
            // `isSecureContext` is true for HTTPS and for localhost. Anything else — a LAN IP,
            // a plain-HTTP staging box — has no WebAuthn at all, and that is a deployment
            // problem the user can act on, not a broken browser.
            if (!window.isSecureContext) {
                return 'insecure-context' as const;
            }

            if (typeof window.PublicKeyCredential === 'undefined') {
                return 'unsupported' as const;
            }

            try {
                const available = await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                return available ? ('supported' as const) : ('unsupported' as const);
            } catch {
                return 'unsupported' as const;
            }
        };

        probe().then((result) => {
            if (!cancelled) {
                setSupport(result);
            }
        });

        return () => {
            cancelled = true;
        };
    }, []);

    return support;
}

/**
 * Turn a ceremony failure into copy a human can act on. Every one of these is common in the
 * field, and they call for completely different responses — collapsing them into one "try
 * again" message makes a config bug indistinguishable from a mistyped PIN.
 */
export function describeWebAuthnError(error: unknown): string {
    if (!(error instanceof Error)) {
        return 'The security prompt failed unexpectedly. Please try again.';
    }

    switch (error.name) {
        case 'NotAllowedError':
            // Covers both an explicit cancel and the ceremony timing out — the browser
            // deliberately does not distinguish them, to avoid leaking user behaviour.
            return 'The prompt was cancelled or timed out. Try again and complete the fingerprint or face check.';
        case 'InvalidStateError':
            // The authenticator itself refused, because it already holds a credential from our
            // excludeCredentials list. That list covers EVERY account, so this is the phone
            // saying "I am already linked" — possibly to somebody else's account entirely.
            return 'This phone is already linked to an account. One device can only be linked to one account. If it is your own phone, ask an administrator to reset your device before registering again.';
        case 'NotSupportedError':
            return 'This device has no built-in authenticator that this site can use.';
        case 'SecurityError':
            // Not the user's fault and not fixable by retrying: the page origin does not match
            // the server's configured RP ID. Say so, so it gets reported rather than retried.
            return 'Security check failed: this site\'s domain does not match its WebAuthn configuration. Please report this to an administrator — it is a server configuration problem, not something you can fix.';
        case 'AbortError':
            return 'The prompt was interrupted. Please try again.';
        default:
            return error.message || 'The security prompt failed. Please try again.';
    }
}

interface RegisterResult {
    ok: boolean;
    message: string;
}

export function useWebAuthn() {
    const [busy, setBusy] = useState(false);

    /**
     * Full registration ceremony: fetch options, prompt, submit the attestation.
     */
    const register = useCallback(async (deviceName: string): Promise<RegisterResult> => {
        setBusy(true);

        try {
            const optionsResponse = await fetch(route('devices.register.options'), {
                method: 'POST',
                headers: jsonHeaders(),
                credentials: 'same-origin',
            });

            if (!optionsResponse.ok) {
                return { ok: false, message: await errorMessage(optionsResponse) };
            }

            const options = await optionsResponse.json();

            // The browser prompt. Must be reached from the user's click without an await on
            // anything slow in between, or iOS Safari discards the user gesture.
            const credential = await startRegistration({ optionsJSON: options });

            const verifyResponse = await fetch(route('devices.register.verify'), {
                method: 'POST',
                headers: jsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ device_name: deviceName, credential }),
            });

            if (!verifyResponse.ok) {
                return { ok: false, message: await errorMessage(verifyResponse) };
            }

            // Mirror the binding token the server just minted. The same value also arrives as
            // an httpOnly cookie; this copy is what survives the cookie being cleared.
            const verified = await verifyResponse.json().catch(() => ({}));

            if (typeof verified.device_token === 'string') {
                storeDeviceToken(verified.device_token);
            }

            return { ok: true, message: verified.message ?? 'Device linked to your account.' };
        } catch (error) {
            return { ok: false, message: describeWebAuthnError(error) };
        } finally {
            setBusy(false);
        }
    }, []);

    /**
     * Assertion half, used by the check-in flow. Returns the credential for the caller to
     * submit alongside its own payload, rather than posting it here — check-in needs the
     * assertion and the geolocation fix to arrive in one request.
     */
    const authenticate = useCallback(async (optionsUrl: string) => {
        const optionsResponse = await fetch(optionsUrl, {
            method: 'POST',
            headers: jsonHeaders(),
            credentials: 'same-origin',
        });

        if (!optionsResponse.ok) {
            throw new Error(await errorMessage(optionsResponse));
        }

        const options = await optionsResponse.json();

        return startAuthentication({ optionsJSON: options });
    }, []);

    return { register, authenticate, busy };
}

function jsonHeaders(): Record<string, string> {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        // Sent on registration too, not only on check-in: it is how the server recognises a
        // handset that is already linked to a different account.
        ...deviceTokenHeader(),
    };
}

export const PASSWORD_CONFIRMATION_REQUIRED = 'password-confirmation-required';

async function errorMessage(response: Response): Promise<string> {
    if (response.status === 419) {
        return 'Your session expired. Reload the page and try again.';
    }

    // Laravel's RequirePassword middleware answers JSON requests with 423 rather than the
    // redirect a page visit would get. Registering an authenticator sits behind it, so the
    // caller has to send the user through the confirm-password page and come back.
    if (response.status === 423) {
        return PASSWORD_CONFIRMATION_REQUIRED;
    }

    try {
        const body = await response.json();
        return body.message ?? `Request failed (${response.status}).`;
    } catch {
        return `Request failed (${response.status}).`;
    }
}
