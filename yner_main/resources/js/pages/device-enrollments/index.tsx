import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { DeviceEnrollmentFormDialog } from '@/components/device-enrollment-form-dialog';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface DeviceEnrollment {
    id: number;
    device_user_id: string;
    biometric_device_id: number;
    employee_id: number;
    employee: { fullName: string } | null;
    biometricDevice: { name: string; serial: string } | null;
}

interface DeviceOption {
    id: number;
    name: string;
    serial: string;
}

interface EmployeeOption {
    id: number;
    fullName: string;
}

interface DeviceEnrollmentsIndexProps {
    enrollments: {
        data: DeviceEnrollment[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    devices: DeviceOption[];
    employees: EmployeeOption[];
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function DeviceEnrollmentsIndex({
    enrollments,
    devices,
    employees,
    actions,
    status,
}: DeviceEnrollmentsIndexProps) {
    const [dialog, setDialog] = useState<{ record: DeviceEnrollment | null } | null>(null);
    const [deleting, setDeleting] = useState<DeviceEnrollment | null>(null);

    return (
        <AppLayout>
            <Head title="Device Enrollments" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Device Enrollments</h1>
                    <p className="text-sm text-muted-foreground">Map device user IDs to employees</p>
                </div>
                {actions.create && (
                    <Button onClick={() => setDialog({ record: null })}>
                        <Plus className="h-4 w-4" /> New Enrollment
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
                                <TableHead>Device</TableHead>
                                <TableHead>Employee</TableHead>
                                <TableHead>Device User ID</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {enrollments.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={4} className="py-10 text-center text-muted-foreground">
                                        No enrollments yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                enrollments.data.map((enrollment) => (
                                    <TableRow key={enrollment.id}>
                                        <TableCell className="font-medium">
                                            {enrollment.biometricDevice
                                                ? `${enrollment.biometricDevice.name} (${enrollment.biometricDevice.serial})`
                                                : '—'}
                                        </TableCell>
                                        <TableCell>{enrollment.employee?.fullName ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {enrollment.device_user_id}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {actions.update && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    onClick={() => setDialog({ record: enrollment })}
                                                >
                                                    <Pencil className="h-4 w-4" /> Edit
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setDeleting(enrollment)}
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
                <Pagination links={enrollments.links} />
            </div>

            {dialog && (
                <DeviceEnrollmentFormDialog
                    record={dialog.record}
                    devices={devices}
                    employees={employees}
                    onClose={() => setDialog(null)}
                />
            )}

            {deleting && (
                <ConfirmDialog
                    title="Delete enrollment"
                    description="Delete this enrollment? This action cannot be undone."
                    onCancel={() => setDeleting(null)}
                    onConfirm={() =>
                        router.delete(route('device-enrollments.destroy', deleting.id), {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
