import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ClipboardList, Clock, ShieldCheck, ShieldX, Smartphone, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { DeviceResetRequestDialog } from '@/components/device-reset-request-dialog';
import { Pagination } from '@/components/pagination';
import { SortableHead } from '@/components/sortable-head';
import { SearchInput, TableToolbar, nextDirection, visitIndex, type IndexFilters } from '@/components/table-toolbar';
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
    is_linked: boolean;
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

interface Binding {
    has_device: boolean;
    device_name: string | null;
    last_used_at: string | null;
    registered_at: string | null;
    reset_pending: boolean;
    reset_approved_until: string | null;
}

interface DevicesIndexProps {
    devices: {
        data: Device[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: IndexFilters;
    stats: { active: number; revoked: number; mine: number };
    binding: Binding;
    actions: { register: boolean; requestReset: boolean; reviewResets: boolean };
    isAdmin: boolean;
    rpId: string;
    status?: string;
}

export default function DevicesIndex({ devices, filters, stats, binding, actions, isAdmin, rpId, status }: DevicesIndexProps) {
    const support = useWebAuthnSupport();
    const [registering, setRegistering] = useState(false);
    const [requestingReset, setRequestingReset] = useState(false);
    const [revoking, setRevoking] = useState<Device | null>(null);

    const applySort = (column: string) =>
        visitIndex('devices.index', {
            ...filters,
            sort: column,
            direction: nextDirection(column, filters.sort, filters.direction),
        });

    return (
        <AppLayout>
            <Head title="My Device" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">My Device</h1>
                    <p className="text-sm text-muted-foreground">
                        One phone is linked to your account, and only that phone can check you in
                    </p>
                </div>
                {actions.reviewResets && (
                    <Button variant="outline" onClick={() => router.visit(route('device-reset-requests.index'))}>
                        <ClipboardList className="h-4 w-4" /> Device resets
                    </Button>
                )}
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

            {/*
              The employee-facing card. Deliberately not a list: showing a table of "your
              devices" would suggest there could be more than one, which is exactly the mental
              model this feature removes.
            */}
            <Card className="mt-6">
                <CardContent className="p-6">
                    {binding.has_device ? (
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex gap-4">
                                <div className="rounded-lg bg-emerald-50 p-3 text-emerald-600">
                                    <ShieldCheck className="h-6 w-6" />
                                </div>
                                <div>
                                    <div className="text-lg font-semibold">{binding.device_name}</div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Linked since {binding.registered_at ?? 'recently'} · Last used{' '}
                                        {binding.last_used_at ?? 'never'}
                                    </p>
                                </div>
                            </div>
                            {actions.requestReset && (
                                <Button variant="outline" onClick={() => setRequestingReset(true)}>
                                    <Smartphone className="h-4 w-4" /> I have a new phone
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex gap-4">
                                <div className="rounded-lg bg-blue-50 p-3 text-blue-600">
                                    <Smartphone className="h-6 w-6" />
                                </div>
                                <div>
                                    <div className="text-lg font-semibold">No device linked</div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {actions.register
                                            ? 'Link this phone to check in from outside a biometric terminal.'
                                            : 'Your previous device was unlinked. An administrator must approve a reset before you can link a phone.'}
                                    </p>
                                </div>
                            </div>
                            {actions.register && support === 'supported' && (
                                <Button onClick={() => setRegistering(true)}>
                                    <Smartphone className="h-4 w-4" /> Link this device
                                </Button>
                            )}
                            {actions.requestReset && (
                                <Button variant="outline" onClick={() => setRequestingReset(true)}>
                                    <Smartphone className="h-4 w-4" /> Request a device reset
                                </Button>
                            )}
                        </div>
                    )}

                    {binding.reset_pending && (
                        <div className="mt-4 flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <Clock className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div>
                                <p className="font-semibold">Device reset awaiting review</p>
                                <p className="mt-1">
                                    Keep using your current phone until an administrator decides. You will be
                                    notified either way.
                                </p>
                            </div>
                        </div>
                    )}

                    {binding.reset_approved_until && !binding.has_device && (
                        <div className="mt-4 flex gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                            <Clock className="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                            <div>
                                <p className="font-semibold">Reset approved</p>
                                <p className="mt-1">
                                    Link your new phone before {binding.reset_approved_until}. After that the
                                    approval expires and you will need to ask again.
                                </p>
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>

            {isAdmin && (
                <>
                    <div className="mt-8 grid gap-4 sm:grid-cols-3">
                        <StatCard
                            icon={ShieldCheck}
                            iconClass="bg-emerald-50 text-emerald-600"
                            label="Linked"
                            value={stats.active}
                        />
                        <StatCard icon={ShieldX} iconClass="bg-rose-50 text-rose-600" label="Revoked" value={stats.revoked} />
                        <StatCard icon={Smartphone} iconClass="bg-blue-50 text-blue-600" label="Mine" value={stats.mine} />
                    </div>

                    <Card className="mt-4">
                        <CardContent className="p-0">
                            <TableToolbar>
                                <SearchInput
                                    value={filters.search}
                                    onSearch={(search) => visitIndex('devices.index', { ...filters, search })}
                                    placeholder="Search device name or owner…"
                                    className="min-w-0 flex-1"
                                />
                            </TableToolbar>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <SortableHead column="device_name" label="Device" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                            <SortableHead column="owner" label="Owner" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                            <SortableHead column="registered_at" label="Registered" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                            <SortableHead column="last_used_at" label="Last Used" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                            <SortableHead column="status" label="Status" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {devices.data.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                                                    {filters.search
                                                        ? 'No devices match your search.'
                                                        : 'No devices linked yet.'}
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
                                                                Registered for {device.rp_id}, not {rpId} — needs a reset
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {device.owner ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {device.registered_at ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {device.last_used_at ?? 'Never'}
                                                        {device.last_used_ip && (
                                                            // Audit context only — never used to decide
                                                            // whether a check-in is allowed.
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
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-3">
                        <Pagination links={devices.links} />
                    </div>
                </>
            )}

            {registering && <DeviceRegisterDialog onClose={() => setRegistering(false)} />}

            {requestingReset && <DeviceResetRequestDialog onClose={() => setRequestingReset(false)} />}

            {revoking && (
                <ConfirmDialog
                    title="Revoke device"
                    description={`"${revoking.device_name}" will no longer be able to check in, and ${revoking.owner ?? 'its owner'} will need an approved device reset before linking another. Past check-ins made with it are kept for the audit trail.`}
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
