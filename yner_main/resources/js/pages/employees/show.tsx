import { Head, Link } from '@inertiajs/react';
import { route } from 'ziggy-js';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

interface EmployeeShowProps {
    employee: {
        id: number;
        employee_code: string;
        fullName: string;
        phone: string | null;
        hire_date: string | null;
        status: string;
        department: { name: string } | null;
        workSchedule: { name: string } | null;
        user: { email: string } | null;
    };
    actions: { update: boolean };
}

export default function ShowEmployee({ employee, actions }: EmployeeShowProps) {
    return (
        <AppLayout header={<h2 className="text-lg font-semibold">{employee.fullName}</h2>}>
            <Head title={employee.fullName} />

            <Card className="max-w-2xl">
                <CardContent className="grid grid-cols-3 gap-y-3 p-6 text-sm">
                    <div className="text-muted-foreground">Employee Code</div>
                    <div className="col-span-2">{employee.employee_code}</div>

                    <div className="text-muted-foreground">Department</div>
                    <div className="col-span-2">{employee.department?.name ?? '—'}</div>

                    <div className="text-muted-foreground">Work Schedule</div>
                    <div className="col-span-2">{employee.workSchedule?.name ?? '—'}</div>

                    <div className="text-muted-foreground">Phone</div>
                    <div className="col-span-2">{employee.phone ?? '—'}</div>

                    <div className="text-muted-foreground">Hire Date</div>
                    <div className="col-span-2">{employee.hire_date ?? '—'}</div>

                    <div className="text-muted-foreground">Status</div>
                    <div className="col-span-2">
                        <Badge variant={employee.status === 'active' ? 'success' : 'secondary'}>
                            {employee.status.charAt(0).toUpperCase() + employee.status.slice(1)}
                        </Badge>
                    </div>

                    <div className="text-muted-foreground">Linked Account</div>
                    <div className="col-span-2">{employee.user?.email ?? 'None'}</div>
                </CardContent>
            </Card>

            <div className="mt-3 flex gap-2">
                <Button variant="link" asChild>
                    <Link href={route('employees.index')}>Back to list</Link>
                </Button>
                {actions.update && (
                    <Button variant="outline" asChild>
                        <Link href={route('employees.edit', employee.id)}>Edit</Link>
                    </Button>
                )}
            </div>
        </AppLayout>
    );
}
