import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, LogIn, LogOut, MapPin, Smartphone } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { GeolocationError } from '@/components/check-in/geolocation-error';
import { GeolocationFailure, type GeolocationErrorKind, useGeolocation } from '@/components/check-in/use-geolocation';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { describeWebAuthnError, useWebAuthn, useWebAuthnSupport } from '@/components/webauthn/use-webauthn';
import { WebAuthnUnavailable } from '@/components/webauthn/webauthn-unavailable';
import AppLayout from '@/layouts/app-layout';
import { deviceTokenHeader } from '@/lib/device-token';

interface CheckInShowProps {
    employee: { name: string; employee_code: string } | null;
    today?: { checked_in_at: string | null; checked_out_at: string | null };
    location?: { name: string; address: string | null; radius_meters: number } | null;
    schedule?: { name: string; start_time: string; end_time: string } | null;
    windows?: { in: { opens: string; closes: string }; out: { opens: string; closes: string } } | null;
    // The one device linked to this account, or null. The distinction matters: "no device"
    // means offer registration, "a device, but not this one" means offer a reset request.
    linkedDevice?: { name: string; rp_id_matches: boolean } | null;
    maxAccuracyMeters?: number;
    actions?: { create: boolean };
}

type Outcome = { kind: 'success'; message: string; flagged: boolean } | { kind: 'error'; message: string; distance: number | null } | null;

export default function CheckInShow({
    employee,
    today,
    location,
    schedule,
    windows,
    linkedDevice = null,
    maxAccuracyMeters = 100,
    actions,
}: CheckInShowProps) {
    const support = useWebAuthnSupport();
    const { authenticate } = useWebAuthn();
    const { locate } = useGeolocation();

    const [busy, setBusy] = useState<'in' | 'out' | null>(null);
    const [outcome, setOutcome] = useState<Outcome>(null);
    const [geoError, setGeoError] = useState<GeolocationErrorKind | null>(null);
    const [state, setState] = useState(today ?? { checked_in_at: null, checked_out_at: null });

    /**
     * One handler chain, in a fixed order: position, then assertion, then POST.
     *
     * The order matters and is not cosmetic. Both the location prompt and the WebAuthn prompt
     * are user-gesture-sensitive on iOS Safari, and a biometric prompt raised after a 15-second
     * geolocation timeout has outlived its gesture — the OS dismisses it before the employee
     * can respond. Taking the fix first keeps the ceremony inside the tap that started it.
     */
    const punch = async (direction: 'in' | 'out') => {
        setBusy(direction);
        setOutcome(null);
        setGeoError(null);

        try {
            const fix = await locate();

            // Checked here purely to fail fast with useful copy — the server enforces its own
            // ceiling regardless, and that check is the one that counts.
            if (fix.accuracy_meters > maxAccuracyMeters) {
                setOutcome({
                    kind: 'error',
                    message: `Your location is only accurate to about ${fix.accuracy_meters} m. Move outdoors or somewhere with a clearer view of the sky and try again.`,
                    distance: null,
                });

                return;
            }

            // Never conditional. The server refuses any check-in it cannot tie to the one
            // device linked to this account, so an unsigned request is a wasted round trip.
            const credential = await authenticate(route('check-in.assertion-options'));

            const response = await fetch(route('check-in.store'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                    // Proof this is the same handset that was enrolled, mirrored from
                    // localStorage in case the httpOnly cookie has been cleared.
                    ...deviceTokenHeader(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ direction, ...fix, credential }),
            });

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                setOutcome({
                    kind: 'error',
                    message: body.message ?? 'The check-in could not be recorded. Please try again.',
                    distance: body.distance_meters ?? null,
                });

                return;
            }

            setOutcome({ kind: 'success', message: body.message, flagged: Boolean(body.flagged) });
            setState((current) =>
                direction === 'in'
                    ? { ...current, checked_in_at: body.punched_at }
                    : { ...current, checked_out_at: body.punched_at },
            );
        } catch (error) {
            if (error instanceof GeolocationFailure) {
                setGeoError(error.kind);

                return;
            }

            setOutcome({ kind: 'error', message: describeWebAuthnError(error), distance: null });
        } finally {
            setBusy(null);
        }
    };

    if (!employee) {
        return (
            <AppLayout>
                <Head title="Check In" />
                <h1 className="text-2xl font-bold tracking-tight">Check In</h1>
                <Card className="mt-6">
                    <CardContent className="p-6 text-sm text-muted-foreground">
                        Your account is not linked to an active employee record, so there is nothing to
                        check in against. Please contact HR.
                    </CardContent>
                </Card>
            </AppLayout>
        );
    }

    const needsDevice = linkedDevice === null;
    const canPunch = actions?.create && support === 'supported' && !needsDevice;

    return (
        <AppLayout>
            <Head title="Check In" />

            <div>
                <h1 className="text-2xl font-bold tracking-tight">Check In</h1>
                <p className="text-sm text-muted-foreground">
                    {employee.name} · {employee.employee_code}
                </p>
            </div>

            {(support === 'insecure-context' || support === 'unsupported') && (
                <div className="mt-6">
                    <WebAuthnUnavailable support={support} />
                </div>
            )}

            {needsDevice && support === 'supported' && (
                <div className="mt-6 flex gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                    <Smartphone className="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                    <div>
                        <p className="font-semibold">Link a device first</p>
                        <p className="mt-1">
                            Check-in requires the one phone linked to your account, so we can confirm it is
                            really you.{' '}
                            <Link href={route('devices.index')} className="font-semibold underline">
                                Link this device
                            </Link>
                            , then come back here.
                        </p>
                    </div>
                </div>
            )}

            {linkedDevice && !linkedDevice.rp_id_matches && (
                <div className="mt-6 flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                    <div>
                        <p className="font-semibold">Your linked device was registered on another domain</p>
                        <p className="mt-1">
                            "{linkedDevice.name}" cannot produce a valid signature here. Ask an administrator
                            for a device reset so you can link it again.
                        </p>
                    </div>
                </div>
            )}

            {!location && (
                <div className="mt-6 flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <MapPin className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                    <div>
                        <p className="font-semibold">No work location assigned</p>
                        <p className="mt-1">
                            Neither you nor your department has a work location set, so your position cannot
                            be checked. Contact HR before trying to check in.
                        </p>
                    </div>
                </div>
            )}

            <div className="mt-6 grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardContent className="p-5">
                        <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                            <MapPin className="h-4 w-4" /> Work location
                        </div>
                        <div className="mt-2 text-lg font-semibold">{location?.name ?? 'Not assigned'}</div>
                        {location && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                {location.address ? `${location.address} · ` : ''}
                                You must be within {location.radius_meters} m.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-5">
                        <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                            <Clock className="h-4 w-4" /> Schedule
                        </div>
                        <div className="mt-2 text-lg font-semibold">
                            {schedule ? `${schedule.start_time} – ${schedule.end_time}` : 'Not assigned'}
                        </div>
                        {windows && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                Check in {windows.in.opens}–{windows.in.closes} · Check out{' '}
                                {windows.out.opens}–{windows.out.closes}
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-4">
                <CardContent className="p-6">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-lg border border-border p-4">
                            <div className="text-sm text-muted-foreground">Checked in</div>
                            <div className="mt-1 text-2xl font-bold tracking-tight">
                                {state.checked_in_at ?? '—'}
                            </div>
                        </div>
                        <div className="rounded-lg border border-border p-4">
                            <div className="text-sm text-muted-foreground">Checked out</div>
                            <div className="mt-1 text-2xl font-bold tracking-tight">
                                {state.checked_out_at ?? '—'}
                            </div>
                        </div>
                    </div>

                    <div className="mt-5 grid gap-3 sm:grid-cols-2">
                        <Button
                            size="lg"
                            className="h-16 text-base"
                            disabled={!canPunch || busy !== null || Boolean(state.checked_in_at)}
                            onClick={() => punch('in')}
                        >
                            <LogIn className="h-5 w-5" />
                            {busy === 'in' ? 'Checking in…' : 'Check In'}
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            className="h-16 text-base"
                            disabled={!canPunch || busy !== null || Boolean(state.checked_out_at)}
                            onClick={() => punch('out')}
                        >
                            <LogOut className="h-5 w-5" />
                            {busy === 'out' ? 'Checking out…' : 'Check Out'}
                        </Button>
                    </div>

                    {busy && (
                        <p className="mt-3 text-center text-sm text-muted-foreground">
                            Finding your location, then confirming your identity — keep this page open.
                        </p>
                    )}

                    {geoError && (
                        <div className="mt-4">
                            <GeolocationError kind={geoError} />
                        </div>
                    )}

                    {outcome?.kind === 'success' && (
                        <div className="mt-4 flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                            <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                            <div>
                                <p className="font-semibold">{outcome.message}</p>
                                {outcome.flagged && (
                                    <p className="mt-1">
                                        This punch was recorded but flagged for review because of unusual
                                        movement since your last one.
                                    </p>
                                )}
                            </div>
                        </div>
                    )}

                    {outcome?.kind === 'error' && (
                        <div className="mt-4 flex gap-3 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-rose-600" />
                            <div>
                                <p className="font-semibold">{outcome.message}</p>
                                {outcome.distance !== null && (
                                    // Display only. The server computed this from the raw coordinates;
                                    // the client never calculates or sends a distance of its own.
                                    <p className="mt-1">
                                        You are about {outcome.distance} m from {location?.name ?? 'your work location'}.
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>
        </AppLayout>
    );
}
