import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ShieldCheck, ShieldX, Smartphone, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { DeviceRegisterDialog } from '@/components/webauthn/device-register-dialog';
import { useWebAuthnSupport } from '@/components/webauthn/use-webauthn';
import { WebAuthnUnavailable } from '@/components/webauthn/webauthn-unavailable';
import AppLayout from '@/layouts/app-layout';

interface Device {
    id: number;
    device_name: string;
    owner: string | null;
    is_own: boolean;
    status: string;
    status_label: string;
    rp_id: string;
    rp_id_matches: boolean;
    transports: string[];
    last_used_at: string | null;
    last_used_ip: string | null;
    registered_at: string | null;
    revoked_at: string | null;
    revoked_reason: string | null;
    can_delete: boolean;
}

interface DevicesIndexProps {
    devices: {
        data: Device[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    stats: { active: number; revoked: number; mine: number };
    actions: { register: boolean };
    isAdmin: boolean;
    rpId: string;
    status?: string;
}

export default function DevicesIndex({ devices, stats, actions, isAdmin, rpId, status }: DevicesIndexProps) {
    const support = useWebAuthnSupport();
    const [registering, setRegistering] = useState(false);
    const [revoking, setRevoking] = useState<Device | null>(null);

    return (
        <AppLayout>
            <Head title="My Devices" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Devices</h1>
                    <p className="text-sm text-muted-foreground">
                        Phones registered to check in from outside a biometric terminal
                    </p>
                </div>
                {actions.register && support === 'supported' && (
                    <Button onClick={() => setRegistering(true)}>
                        <Smartphone className="h-4 w-4" /> Register this device
                    </Button>
                )}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-3">
                <StatCard icon={ShieldCheck} iconClass="bg-emerald-50 text-emerald-600" label="Active" value={stats.active} />
                <StatCard icon={ShieldX} iconClass="bg-rose-50 text-rose-600" label="Revoked" value={stats.revoked} />
                <StatCard icon={Smartphone} iconClass="bg-blue-50 text-blue-600" label="My Devices" value={stats.mine} />
            </div>

            {status && (
                <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700">
                    {status}
                </div>
            )}

            {(support === 'insecure-context' || support === 'unsupported') && (
                <div className="mt-4">
                    <WebAuthnUnavailable support={support} />
                </div>
            )}

            {actions.register && support === 'supported' && stats.mine === 0 && (
                <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                    You have no registered device yet. Register this phone to check in from the field.
                </div>
            )}

            <Card className="mt-6">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Device</TableHead>
                                {isAdmin && <TableHead>Owner</TableHead>}
                                <TableHead>Registered</TableHead>
                                <TableHead>Last Used</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {devices.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={isAdmin ? 6 : 5} className="py-10 text-center text-muted-foreground">
                                        No devices registered yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                devices.data.map((device) => (
                                    <TableRow key={device.id}>
                                        <TableCell>
                                            <div className="font-medium">{device.device_name}</div>
                                            {!device.rp_id_matches && (
                                                <div className="mt-1 flex items-center gap-1 text-xs text-amber-700">
                                                    <AlertTriangle className="h-3 w-3" />
                                                    Registered for {device.rp_id}, not {rpId} — re-register it
                                                </div>
                                            )}
                                        </TableCell>
                                        {isAdmin && (
                                            <TableCell className="text-muted-foreground">{device.owner ?? '—'}</TableCell>
                                        )}
                                        <TableCell className="text-muted-foreground">
                                            {device.registered_at ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {device.last_used_at ?? 'Never'}
                                            {device.last_used_ip && (
                                                <div className="text-xs">{device.last_used_ip}</div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={
                                                    device.status === 'active'
                                                        ? 'rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700'
                                                        : 'rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground'
                                                }
                                            >
                                                {device.status_label}
                                            </span>
                                            {device.revoked_reason && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {device.revoked_reason}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {device.can_delete && device.status === 'active' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setRevoking(device)}
                                                >
                                                    <Trash2 className="h-4 w-4" /> Revoke
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <div className="mt-3">
                <Pagination links={devices.links} />
            </div>

            {registering && <DeviceRegisterDialog onClose={() => setRegistering(false)} />}

            {revoking && (
                <ConfirmDialog
                    title="Revoke device"
                    description={`"${revoking.device_name}" will no longer be able to check in. Past check-ins made with it are kept for the audit trail.`}
                    onCancel={() => setRevoking(null)}
                    onConfirm={() =>
                        router.delete(route('devices.destroy', revoking.id), {
                            preserveScroll: true,
                            onSuccess: () => setRevoking(null),
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
