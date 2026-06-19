import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';

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
    const destroy = (device: BiometricDevice) => {
        if (confirm('Delete this device?')) {
            router.delete(route('biometric-devices.destroy', device.id));
        }
    };

    return (
        <AppLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Biometric Devices</h2>
                    {actions.create && (
                        <Button asChild size="sm">
                            <Link href={route('biometric-devices.create')}>New Device</Link>
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Biometric Devices" />

            {status && <div className="mb-4 text-sm font-medium text-emerald-600">{status}</div>}

            <Card>
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
                                    <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                        No devices yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                devices.data.map((device) => (
                                    <TableRow key={device.id}>
                                        <TableCell>{device.name}</TableCell>
                                        <TableCell className="capitalize">{device.type}</TableCell>
                                        <TableCell>{device.serial}</TableCell>
                                        <TableCell>
                                            <Badge variant={device.status === 'active' ? 'success' : 'secondary'}>
                                                {device.status.charAt(0).toUpperCase() + device.status.slice(1)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{device.enrollments_count}</TableCell>
                                        <TableCell className="text-right">
                                            {actions.update && (
                                                <Button asChild variant="outline" size="sm" className="mr-2">
                                                    <Link href={route('biometric-devices.edit', device.id)}>Edit</Link>
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => destroy(device)}
                                                >
                                                    Delete
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
        </AppLayout>
    );
}
