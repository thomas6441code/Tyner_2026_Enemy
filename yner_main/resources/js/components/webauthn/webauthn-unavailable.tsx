import { Lock, ShieldOff } from 'lucide-react';

import type { WebAuthnSupport } from '@/components/webauthn/use-webauthn';

/**
 * The two failure modes look identical to a user and need opposite responses, so they get
 * separate copy:
 *
 *   insecure-context — the site is not on HTTPS. Actionable, and usually means someone opened
 *                      a LAN IP instead of the deployed domain.
 *   unsupported      — the hardware genuinely has no platform authenticator. Nothing to fix
 *                      here; they must use a biometric terminal instead.
 */
export function WebAuthnUnavailable({ support }: { support: WebAuthnSupport }) {
    if (support === 'insecure-context') {
        return (
            <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <Lock className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                <div>
                    <p className="font-semibold">This page is not on a secure connection</p>
                    <p className="mt-1">
                        Device security prompts only work over HTTPS. Open this site using its full{' '}
                        <code className="rounded bg-amber-100 px-1">https://</code> address rather than an IP
                        address or plain HTTP, then try again.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex gap-3 rounded-lg border border-border bg-muted/50 p-4 text-sm">
            <ShieldOff className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
            <div>
                <p className="font-semibold">This device cannot be registered</p>
                <p className="mt-1 text-muted-foreground">
                    No built-in fingerprint or face authenticator was found. Use a phone or laptop with a
                    biometric sensor, or record your attendance at a biometric terminal instead.
                </p>
            </div>
        </div>
    );
}
