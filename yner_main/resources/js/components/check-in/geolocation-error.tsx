import { Ban, Compass, Lock, Timer } from 'lucide-react';

import type { GeolocationErrorKind } from '@/components/check-in/use-geolocation';

/**
 * Four failure modes that a single "could not get your location" message would flatten into
 * one unactionable dead end. Each needs a different response from the person holding the phone,
 * so each gets its own copy.
 */
const COPY: Record<GeolocationErrorKind, { icon: typeof Ban; title: string; body: string }> = {
    'permission-denied': {
        icon: Ban,
        title: 'Location access is blocked',
        body: 'Your browser is refusing to share your location with this site. Open the site settings — usually the padlock or ⓘ icon beside the address bar — allow Location, then reload this page and try again.',
    },
    'position-unavailable': {
        icon: Compass,
        title: 'Your position could not be determined',
        body: 'Your device could not get a fix. Move outdoors or near a window, make sure location services are switched on for your phone as a whole, and try again.',
    },
    timeout: {
        icon: Timer,
        title: 'Finding your location took too long',
        body: 'The GPS did not return a position in time. This is common indoors and in dense buildings — step outside for a moment and tap again.',
    },
    unavailable: {
        icon: Lock,
        // Almost always an insecure context rather than a browser without the API: the same
        // condition that removes navigator.credentials removes geolocation.
        title: 'Location is not available on this page',
        body: 'Location only works over a secure HTTPS connection. Open this site using its full https:// address rather than an IP address, then try again. If you are already on HTTPS, this browser does not support location.',
    },
};

export function GeolocationError({ kind }: { kind: GeolocationErrorKind }) {
    const { icon: Icon, title, body } = COPY[kind];

    return (
        <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <Icon className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div>
                <p className="font-semibold">{title}</p>
                <p className="mt-1">{body}</p>
            </div>
        </div>
    );
}
