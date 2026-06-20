import { Head } from '@inertiajs/react';
import { UserCheck, Users } from 'lucide-react';

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
        <AppLayout>
            <Head title="HR Dashboard" />

            <div>
                <h1 className="text-2xl font-bold tracking-tight">HR Dashboard</h1>
                <p className="text-sm text-muted-foreground">Workforce at a glance</p>
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardContent className="flex items-center gap-4 p-5">
                        <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                            <Users className="h-5 w-5" />
                        </span>
                        <div>
                            <div className="text-2xl font-bold tracking-tight">{employeeCount}</div>
                            <div className="text-sm text-muted-foreground">Employees</div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center gap-4 p-5">
                        <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <UserCheck className="h-5 w-5" />
                        </span>
                        <div>
                            <div className="text-2xl font-bold tracking-tight">{activeEmployeeCount}</div>
                            <div className="text-sm text-muted-foreground">Active Employees</div>
                        </div>
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
                                    <TableCell colSpan={2} className="py-10 text-center text-muted-foreground">
                                        No departments yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                departments.map((department) => (
                                    <TableRow key={department.id}>
                                        <TableCell className="font-medium">{department.name}</TableCell>
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
