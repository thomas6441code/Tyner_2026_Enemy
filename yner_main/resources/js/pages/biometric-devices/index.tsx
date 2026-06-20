import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { BiometricDeviceFormDialog } from '@/components/biometric-device-form-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
}

interface BiometricDevicesIndexProps {
    devices: {
        data: BiometricDevice[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function BiometricDevicesIndex({ devices, actions, status }: BiometricDevicesIndexProps) {
    const [dialog, setDialog] = useState<{ record: BiometricDevice | null } | null>(null);
    const [deleting, setDeleting] = useState<BiometricDevice | null>(null);

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
                                <TableHead>Enrollments</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {devices.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
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
                                        <TableCell>{device.enrollments_count}</TableCell>
                                        <TableCell className="text-right">
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
        </AppLayout>
    );
}
