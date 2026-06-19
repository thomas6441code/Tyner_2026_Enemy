import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';

import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface Employee {
    id: number;
    employee_code: string;
    fullName: string;
    status: string;
    department: { name: string } | null;
    workSchedule: { name: string } | null;
}

interface EmployeesIndexProps {
    employees: {
        data: Employee[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function EmployeesIndex({ employees, actions, status }: EmployeesIndexProps) {
    const destroy = (employee: Employee) => {
        if (confirm('Delete this employee?')) {
            router.delete(route('employees.destroy', employee.id));
        }
    };

    return (
        <AppLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Employees</h2>
                    {actions.create && (
                        <Button asChild size="sm">
                            <Link href={route('employees.create')}>New Employee</Link>
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Employees" />

            {status && <div className="mb-4 text-sm font-medium text-emerald-600">{status}</div>}

            <Card>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Work Schedule</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {employees.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                        No employees yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                employees.data.map((employee) => (
                                    <TableRow key={employee.id}>
                                        <TableCell>{employee.employee_code}</TableCell>
                                        <TableCell>{employee.fullName}</TableCell>
                                        <TableCell>{employee.department?.name ?? '—'}</TableCell>
                                        <TableCell>{employee.workSchedule?.name ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={employee.status === 'active' ? 'success' : 'secondary'}>
                                                {employee.status.charAt(0).toUpperCase() + employee.status.slice(1)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button asChild variant="outline" size="sm" className="mr-2">
                                                <Link href={route('employees.show', employee.id)}>View</Link>
                                            </Button>
                                            {actions.update && (
                                                <Button asChild variant="outline" size="sm" className="mr-2">
                                                    <Link href={route('employees.edit', employee.id)}>Edit</Link>
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => destroy(employee)}
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
                <Pagination links={employees.links} />
            </div>
        </AppLayout>
    );
}
