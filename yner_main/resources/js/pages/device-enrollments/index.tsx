import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';

import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface DeviceEnrollment {
    id: number;
    device_user_id: string;
    employee: { fullName: string } | null;
    biometricDevice: { name: string; serial: string } | null;
}

interface DeviceEnrollmentsIndexProps {
    enrollments: {
        data: DeviceEnrollment[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function DeviceEnrollmentsIndex({ enrollments, actions, status }: DeviceEnrollmentsIndexProps) {
    const destroy = (enrollment: DeviceEnrollment) => {
        if (confirm('Delete this enrollment?')) {
            router.delete(route('device-enrollments.destroy', enrollment.id));
        }
    };

    return (
        <AppLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Device Enrollments</h2>
                    {actions.create && (
                        <Button asChild size="sm">
                            <Link href={route('device-enrollments.create')}>New Enrollment</Link>
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Device Enrollments" />

            {status && <div className="mb-4 text-sm font-medium text-emerald-600">{status}</div>}

            <Card>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Device</TableHead>
                                <TableHead>Employee</TableHead>
                                <TableHead>Device User ID</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {enrollments.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={4} className="py-8 text-center text-muted-foreground">
                                        No enrollments yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                enrollments.data.map((enrollment) => (
                                    <TableRow key={enrollment.id}>
                                        <TableCell>
                                            {enrollment.biometricDevice
                                                ? `${enrollment.biometricDevice.name} (${enrollment.biometricDevice.serial})`
                                                : '—'}
                                        </TableCell>
                                        <TableCell>{enrollment.employee?.fullName ?? '—'}</TableCell>
                                        <TableCell>{enrollment.device_user_id}</TableCell>
                                        <TableCell className="text-right">
                                            {actions.update && (
                                                <Button asChild variant="outline" size="sm" className="mr-2">
                                                    <Link href={route('device-enrollments.edit', enrollment.id)}>
                                                        Edit
                                                    </Link>
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => destroy(enrollment)}
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
                <Pagination links={enrollments.links} />
            </div>
        </AppLayout>
    );
}
