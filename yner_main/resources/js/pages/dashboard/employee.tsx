import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

interface EmployeeDashboardProps {
    employee: {
        employee_code: string;
        status: string;
        department: { name: string } | null;
        workSchedule: { name: string } | null;
    } | null;
}

export default function EmployeeDashboard({ employee }: EmployeeDashboardProps) {
    return (
        <AppLayout header={<h2 className="text-lg font-semibold">My Dashboard</h2>}>
            <Head title="My Dashboard" />

            {employee ? (
                <Card className="max-w-xl">
                    <CardContent className="grid grid-cols-3 gap-y-3 p-6 text-sm">
                        <div className="text-muted-foreground">Employee Code</div>
                        <div className="col-span-2">{employee.employee_code}</div>

                        <div className="text-muted-foreground">Department</div>
                        <div className="col-span-2">{employee.department?.name ?? '—'}</div>

                        <div className="text-muted-foreground">Work Schedule</div>
                        <div className="col-span-2">{employee.workSchedule?.name ?? '—'}</div>

                        <div className="text-muted-foreground">Status</div>
                        <div className="col-span-2">
                            <Badge variant={employee.status === 'active' ? 'success' : 'secondary'}>
                                {employee.status.charAt(0).toUpperCase() + employee.status.slice(1)}
                            </Badge>
                        </div>
                    </CardContent>
                </Card>
            ) : (
                <div className="max-w-xl rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
                    No employee record is linked to your account yet. Contact HR to get set up.
                </div>
            )}
        </AppLayout>
    );
}
