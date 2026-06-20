import { Head, router } from '@inertiajs/react';
import { Eye, KeyRound, Pencil, Plus, Trash2, UserCheck, Users, UserX } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmployeeFormDialog } from '@/components/employee-form-dialog';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface Employee {
    id: number;
    employee_code: string;
    fullName: string;
    first_name: string;
    last_name: string;
    phone: string | null;
    hire_date: string | null;
    status: string;
    department_id: number | null;
    work_schedule_id: number | null;
    user_id: number | null;
    department: { name: string } | null;
    workSchedule: { name: string } | null;
    user: { email: string } | null;
}

interface Option {
    id: number;
    name: string;
}

interface UserOption {
    id: number;
    name: string;
    email: string;
}

interface EmployeesIndexProps {
    employees: {
        data: Employee[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    departments: Option[];
    workSchedules: Option[];
    unlinkedUsers: UserOption[];
    stats: { total: number; active: number; inactive: number; unlinked: number };
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

function initials(name: string) {
    const parts = name.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '')).toUpperCase();
}

export default function EmployeesIndex({
    employees,
    departments,
    workSchedules,
    unlinkedUsers,
    stats,
    actions,
    status,
}: EmployeesIndexProps) {
    const [dialog, setDialog] = useState<{ record: Employee | null } | null>(null);
    const [viewing, setViewing] = useState<Employee | null>(null);
    const [deleting, setDeleting] = useState<Employee | null>(null);

    return (
        <AppLayout>
            <Head title="Employees" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Employees</h1>
                    <p className="text-sm text-muted-foreground">Manage staff records and assignments</p>
                </div>
                {actions.create && (
                    <Button onClick={() => setDialog({ record: null })}>
                        <Plus className="h-4 w-4" /> New Employee
                    </Button>
                )}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard icon={Users} iconClass="bg-blue-50 text-blue-600" label="Total Employees" value={stats.total} />
                <StatCard icon={UserCheck} iconClass="bg-emerald-50 text-emerald-600" label="Active" value={stats.active} />
                <StatCard icon={UserX} iconClass="bg-rose-50 text-rose-600" label="Inactive" value={stats.inactive} />
                <StatCard icon={KeyRound} iconClass="bg-amber-50 text-amber-600" label="Without Login" value={stats.unlinked} />
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
                                <TableHead>Employee</TableHead>
                                <TableHead>Code</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Work Schedule</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {employees.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                                        No employees yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                employees.data.map((employee) => (
                                    <TableRow key={employee.id}>
                                        <TableCell>
                                            <div className="flex items-center gap-3">
                                                <Avatar className="h-9 w-9">
                                                    <AvatarFallback className="bg-primary/10 text-xs font-semibold text-primary">
                                                        {initials(employee.fullName)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <span className="font-medium">{employee.fullName}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {employee.employee_code}
                                        </TableCell>
                                        <TableCell>{employee.department?.name ?? '—'}</TableCell>
                                        <TableCell>{employee.workSchedule?.name ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={employee.status === 'active' ? 'success' : 'secondary'}>
                                                {employee.status.charAt(0).toUpperCase() + employee.status.slice(1)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="mr-1"
                                                onClick={() => setViewing(employee)}
                                            >
                                                <Eye className="h-4 w-4" /> View
                                            </Button>
                                            {actions.update && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    onClick={() => setDialog({ record: employee })}
                                                >
                                                    <Pencil className="h-4 w-4" /> Edit
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setDeleting(employee)}
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
                <Pagination links={employees.links} />
            </div>

            {dialog && (
                <EmployeeFormDialog
                    record={dialog.record}
                    departments={departments}
                    workSchedules={workSchedules}
                    unlinkedUsers={unlinkedUsers}
                    onClose={() => setDialog(null)}
                />
            )}

            {viewing && (
                <Dialog open onOpenChange={(open) => !open && setViewing(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{viewing.fullName}</DialogTitle>
                        </DialogHeader>
                        <dl className="grid grid-cols-3 gap-y-3 text-sm">
                            <dt className="text-muted-foreground">Employee Code</dt>
                            <dd className="col-span-2">{viewing.employee_code}</dd>
                            <dt className="text-muted-foreground">Department</dt>
                            <dd className="col-span-2">{viewing.department?.name ?? '—'}</dd>
                            <dt className="text-muted-foreground">Work Schedule</dt>
                            <dd className="col-span-2">{viewing.workSchedule?.name ?? '—'}</dd>
                            <dt className="text-muted-foreground">Phone</dt>
                            <dd className="col-span-2">{viewing.phone ?? '—'}</dd>
                            <dt className="text-muted-foreground">Hire Date</dt>
                            <dd className="col-span-2">{viewing.hire_date ?? '—'}</dd>
                            <dt className="text-muted-foreground">Status</dt>
                            <dd className="col-span-2">
                                <Badge variant={viewing.status === 'active' ? 'success' : 'secondary'}>
                                    {viewing.status.charAt(0).toUpperCase() + viewing.status.slice(1)}
                                </Badge>
                            </dd>
                            <dt className="text-muted-foreground">Linked Account</dt>
                            <dd className="col-span-2">{viewing.user?.email ?? 'None'}</dd>
                        </dl>
                    </DialogContent>
                </Dialog>
            )}

            {deleting && (
                <ConfirmDialog
                    title="Delete employee"
                    description={`Delete "${deleting.fullName}"? This action cannot be undone.`}
                    onCancel={() => setDeleting(null)}
                    onConfirm={() =>
                        router.delete(route('employees.destroy', deleting.id), {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
