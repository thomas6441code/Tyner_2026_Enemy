import { Head, Link } from '@inertiajs/react';
import { route } from 'ziggy-js';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

interface AdminDashboardProps {
    departmentCount: number;
    employeeCount: number;
    activeEmployeeCount: number;
    unlinkedEmployeeCount: number;
}

export default function AdminDashboard({
    departmentCount,
    employeeCount,
    activeEmployeeCount,
    unlinkedEmployeeCount,
}: AdminDashboardProps) {
    return (
        <AppLayout header={<h2 className="text-lg font-semibold">Admin Dashboard</h2>}>
            <Head title="Admin Dashboard" />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardContent className="p-4">
                        <div className="text-sm text-muted-foreground">Departments</div>
                        <div className="text-2xl font-semibold">{departmentCount}</div>
                    </CardContent>
                </Card>
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
                <Card>
                    <CardContent className="p-4">
                        <div className="text-sm text-muted-foreground">Without Login Account</div>
                        <div className="text-2xl font-semibold">{unlinkedEmployeeCount}</div>
                    </CardContent>
                </Card>
            </div>

            <div className="mt-6 flex gap-2">
                <Button asChild size="sm">
                    <Link href={route('employees.create')}>Add Employee</Link>
                </Button>
                <Button asChild variant="outline" size="sm">
                    <Link href={route('departments.create')}>Add Department</Link>
                </Button>
                <Button asChild variant="outline" size="sm">
                    <Link href={route('work-schedules.create')}>Add Work Schedule</Link>
                </Button>
            </div>
        </AppLayout>
    );
}
