import { Head } from '@inertiajs/react';

import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface DepartmentRow {
    id: number;
    name: string;
    employees_count: number;
}

interface HrDashboardProps {
    employeeCount: number;
    activeEmployeeCount: number;
    departments: DepartmentRow[];
}

export default function HrDashboard({ employeeCount, activeEmployeeCount, departments }: HrDashboardProps) {
    return (
        <AppLayout header={<h2 className="text-lg font-semibold">HR Dashboard</h2>}>
            <Head title="HR Dashboard" />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardContent className="p-4">
                        <div className="text-sm text-muted-foreground">Employees</div>
                        <div className="text-2xl font-semibold">{employeeCount}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-sm text-muted-foreground">Active Employees</div>
                        <div className="text-2xl font-semibold">{activeEmployeeCount}</div>
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-6">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Department</TableHead>
                                <TableHead>Employees</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {departments.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={2} className="py-8 text-center text-muted-foreground">
                                        No departments yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                departments.map((department) => (
                                    <TableRow key={department.id}>
                                        <TableCell>{department.name}</TableCell>
                                        <TableCell>{department.employees_count}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
