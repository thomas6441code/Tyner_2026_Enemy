import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { FileText, Fingerprint, Loader2, Pencil, Plus, ScanLine, Trash2, Wifi, WifiOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { route } from 'ziggy-js';

import { BiometricDeviceFormDialog } from '@/components/biometric-device-form-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface BiometricDevice {
    id: number;
    name: string;
    type: string;
    serial: string;
    host: string | null;
    port: number | null;
    username: string | null;
    status: string;
    enrollments_count: number;
    last_checked_at: string | null;
    last_status: 'connected' | 'error' | null;
    last_status_message: string | null;
}

interface ConnectionInfo {
    status: 'connected' | 'error';
    message: string | null;
    checked_at: string;
}

interface DeviceLog {
    device_user_id: string;
    punched_at: string;
    processed_at: string | null;
}

interface BiometricDevicesIndexProps {
    devices: {
        data: BiometricDevice[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    stats: { total: number; active: number; enrollments: number };
    actions: { create: boolean; view: boolean; update: boolean; delete: boolean };
    status?: string;
}

function ConnectionBadge({ info }: { info: ConnectionInfo | null }) {
    if (!info) {
        return <Badge variant="secondary">Not tested</Badge>;
    }

    return (
        <div className="flex flex-col gap-0.5">
            <Badge
                variant={info.status === 'connected' ? 'success' : 'destructive'}
                title={info.message ?? undefined}
            >
                {info.status === 'connected' ? (
                    <Wifi className="h-3 w-3" />
                ) : (
                    <WifiOff className="h-3 w-3" />
                )}
                {info.status === 'connected' ? 'Connected' : 'Error'}
            </Badge>
            <span className="text-xs text-muted-foreground">{new Date(info.checked_at).toLocaleString()}</span>
        </div>
    );
}

export default function BiometricDevicesIndex({ devices, stats, actions, status }: BiometricDevicesIndexProps) {
    const [dialog, setDialog] = useState<{ record: BiometricDevice | null } | null>(null);
    const [deleting, setDeleting] = useState<BiometricDevice | null>(null);
    const [testingId, setTestingId] = useState<number | null>(null);
    const [connectionInfo, setConnectionInfo] = useState<Record<number, ConnectionInfo>>({});
    const [viewingLogs, setViewingLogs] = useState<BiometricDevice | null>(null);
    const [logs, setLogs] = useState<DeviceLog[] | null>(null);
    const [logsLoading, setLogsLoading] = useState(false);
    const [logsError, setLogsError] = useState<string | null>(null);

    useEffect(() => {
        if (!viewingLogs) {
            setLogs(null);
            setLogsError(null);
            return;
        }

        setLogsLoading(true);
        setLogsError(null);
        axios
            .get<{ logs: DeviceLog[] }>(route('biometric-devices.logs', viewingLogs.id))
            .then((response) => setLogs(response.data.logs))
            .catch(() => setLogsError('Unable to load logs for this device.'))
            .finally(() => setLogsLoading(false));
    }, [viewingLogs]);

    function connectionFor(device: BiometricDevice): ConnectionInfo | null {
        if (connectionInfo[device.id]) {
            return connectionInfo[device.id];
        }

        if (!device.last_status || !device.last_checked_at) {
            return null;
        }

        return {
            status: device.last_status,
            message: device.last_status_message,
            checked_at: device.last_checked_at,
        };
    }

    async function handleTestConnection(device: BiometricDevice) {
        setTestingId(device.id);

        try {
            const response = await axios.post<{ ok: boolean; error: string | null; checked_at: string }>(
                route('biometric-devices.test-connection', device.id),
            );
            const result = response.data;
            setConnectionInfo((prev) => ({
                ...prev,
                [device.id]: {
                    status: result.ok ? 'connected' : 'error',
                    message: result.error,
                    checked_at: result.checked_at,
                },
            }));
        } catch (error) {
            const message = axios.isAxiosError(error)
                ? ((error.response?.data as { error?: string } | undefined)?.error ??
                  'Unable to reach the biometric service.')
                : 'Unable to reach the biometric service.';
            setConnectionInfo((prev) => ({
                ...prev,
                [device.id]: { status: 'error', message, checked_at: new Date().toISOString() },
            }));
        } finally {
            setTestingId(null);
        }
    }

    return (
        <AppLayout>
            <Head title="Biometric Devices" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Biometric Devices</h1>
                    <p className="text-sm text-muted-foreground">Manage attendance capture devices</p>
                </div>
                {actions.create && (
                    <Button onClick={() => setDialog({ record: null })}>
                        <Plus className="h-4 w-4" /> New Device
                    </Button>
                )}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-3">
                <StatCard icon={ScanLine} iconClass="bg-primary/10 text-primary" label="Total Devices" value={stats.total} />
                <StatCard icon={ScanLine} iconClass="bg-emerald-50 text-emerald-600" label="Active Devices" value={stats.active} />
                <StatCard icon={Fingerprint} iconClass="bg-amber-50 text-amber-600" label="Total Enrollments" value={stats.enrollments} />
            </div>

            {status && (
                <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700">
                    {status}
                </div>
            )}

            <Card className="mt-6">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Serial</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Connection</TableHead>
                                <TableHead>Enrollments</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {devices.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="py-10 text-center text-muted-foreground">
                                        No devices yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                devices.data.map((device) => (
                                    <TableRow key={device.id}>
                                        <TableCell className="font-medium">{device.name}</TableCell>
                                        <TableCell className="capitalize">{device.type}</TableCell>
                                        <TableCell className="text-muted-foreground">{device.serial}</TableCell>
                                        <TableCell>
                                            <Badge variant={device.status === 'active' ? 'success' : 'secondary'}>
                                                {device.status.charAt(0).toUpperCase() + device.status.slice(1)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <ConnectionBadge info={connectionFor(device)} />
                                        </TableCell>
                                        <TableCell>{device.enrollments_count}</TableCell>
                                        <TableCell className="whitespace-nowrap text-right">
                                            {actions.update && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    disabled={testingId === device.id}
                                                    onClick={() => handleTestConnection(device)}
                                                >
                                                    {testingId === device.id ? (
                                                        <Loader2 className="h-4 w-4 animate-spin" />
                                                    ) : (
                                                        <Wifi className="h-4 w-4" />
                                                    )}
                                                    Test
                                                </Button>
                                            )}
                                            {actions.view && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    onClick={() => setViewingLogs(device)}
                                                >
                                                    <FileText className="h-4 w-4" /> Logs
                                                </Button>
                                            )}
                                            {actions.update && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    onClick={() => setDialog({ record: device })}
                                                >
                                                    <Pencil className="h-4 w-4" /> Edit
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setDeleting(device)}
                                                >
                                                    <Trash2 className="h-4 w-4" /> Delete
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

            {dialog && <BiometricDeviceFormDialog record={dialog.record} onClose={() => setDialog(null)} />}

            {deleting && (
                <ConfirmDialog
                    title="Delete device"
                    description={`Delete "${deleting.name}"? This action cannot be undone.`}
                    onCancel={() => setDeleting(null)}
                    onConfirm={() =>
                        router.delete(route('biometric-devices.destroy', deleting.id), {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        })
                    }
                />
            )}

            {viewingLogs && (
                <Dialog open onOpenChange={(open) => !open && setViewingLogs(null)}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <FileText className="h-5 w-5 text-primary" /> {viewingLogs.name} — Recent Logs
                            </DialogTitle>
                        </DialogHeader>

                        {logsLoading ? (
                            <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                                <Loader2 className="h-4 w-4 animate-spin" /> Loading logs…
                            </div>
                        ) : logsError ? (
                            <p className="py-6 text-center text-sm text-destructive">{logsError}</p>
                        ) : !logs || logs.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No punch logs recorded for this device yet.
                            </p>
                        ) : (
                            <div className="max-h-96 overflow-y-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Device User ID</TableHead>
                                            <TableHead>Punched At</TableHead>
                                            <TableHead>Processed</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {logs.map((log, index) => (
                                            <TableRow key={`${log.device_user_id}-${log.punched_at}-${index}`}>
                                                <TableCell className="font-medium">{log.device_user_id}</TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {new Date(log.punched_at).toLocaleString()}
                                                </TableCell>
                                                <TableCell>
                                                    {log.processed_at ? (
                                                        <Badge variant="success">Processed</Badge>
                                                    ) : (
                                                        <Badge variant="outline">Pending</Badge>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </AppLayout>
    );
}
