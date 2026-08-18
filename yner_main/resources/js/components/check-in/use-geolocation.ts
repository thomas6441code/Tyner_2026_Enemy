import { useCallback, useState } from 'react';

/**
 * A single, deliberate geolocation read for the check-in flow.
 *
 * Not a watch and not a hook that fires on mount: the fix must be taken at the moment the
 * employee taps, as part of the same user gesture that will raise the WebAuthn prompt.
 */

export type GeolocationErrorKind = 'unavailable' | 'permission-denied' | 'position-unavailable' | 'timeout';

export interface Fix {
    latitude: number;
    longitude: number;
    accuracy_meters: number;
}

export class GeolocationFailure extends Error {
    constructor(public readonly kind: GeolocationErrorKind) {
        super(kind);
        this.name = 'GeolocationFailure';
    }
}

/**
 * `maximumAge: 0` is load-bearing, not a default worth tidying away. Without it the browser is
 * free to hand back a cached fix — potentially the one taken at the employee's home that
 * morning — which would sail straight through the geofence. Every check-in must be measured
 * against a position obtained now.
 *
 * `enableHighAccuracy` asks for GPS rather than a coarse network estimate, because the server
 * rejects anything above the configured accuracy ceiling.
 */
const OPTIONS: PositionOptions = {
    enableHighAccuracy: true,
    timeout: 15000,
    maximumAge: 0,
};

export function readPosition(): Promise<Fix> {
    return new Promise((resolve, reject) => {
        // Absent entirely outside a secure context — the same condition that removes
        // navigator.credentials, and worth reporting as such rather than as a browser fault.
        if (typeof navigator === 'undefined' || !navigator.geolocation) {
            reject(new GeolocationFailure('unavailable'));

            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) =>
                resolve({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy_meters: Math.round(position.coords.accuracy),
                }),
            (error) => {
                switch (error.code) {
                    case error.PERMISSION_DENIED:
                        reject(new GeolocationFailure('permission-denied'));
                        break;
                    case error.POSITION_UNAVAILABLE:
                        reject(new GeolocationFailure('position-unavailable'));
                        break;
                    case error.TIMEOUT:
                        reject(new GeolocationFailure('timeout'));
                        break;
                    default:
                        reject(new GeolocationFailure('position-unavailable'));
                }
            },
            OPTIONS,
        );
    });
}

export function useGeolocation() {
    const [locating, setLocating] = useState(false);

    const locate = useCallback(async (): Promise<Fix> => {
        setLocating(true);

        try {
            return await readPosition();
        } finally {
            setLocating(false);
        }
    }, []);

    return { locate, locating };
}
