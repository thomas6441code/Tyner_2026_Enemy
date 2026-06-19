import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';

import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface WorkSchedule {
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    grace_period_minutes: number;
    employees_count: number;
}

interface WorkSchedulesIndexProps {
    workSchedules: {
        data: WorkSchedule[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function WorkSchedulesIndex({ workSchedules, actions, status }: WorkSchedulesIndexProps) {
    const destroy = (workSchedule: WorkSchedule) => {
        if (confirm('Delete this work schedule?')) {
            router.delete(route('work-schedules.destroy', workSchedule.id));
        }
    };

    return (
        <AppLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Work Schedules</h2>
                    {actions.create && (
                        <Button asChild size="sm">
                            <Link href={route('work-schedules.create')}>New Work Schedule</Link>
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Work Schedules" />

            {status && <div className="mb-4 text-sm font-medium text-emerald-600">{status}</div>}

            <Card>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Start</TableHead>
                                <TableHead>End</TableHead>
                                <TableHead>Grace (min)</TableHead>
                                <TableHead>Employees</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {workSchedules.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                        No work schedules yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                workSchedules.data.map((workSchedule) => (
                                    <TableRow key={workSchedule.id}>
                                        <TableCell>{workSchedule.name}</TableCell>
                                        <TableCell>{workSchedule.start_time}</TableCell>
                                        <TableCell>{workSchedule.end_time}</TableCell>
                                        <TableCell>{workSchedule.grace_period_minutes}</TableCell>
                                        <TableCell>{workSchedule.employees_count}</TableCell>
                                        <TableCell className="text-right">
                                            {actions.update && (
                                                <Button asChild variant="outline" size="sm" className="mr-2">
                                                    <Link href={route('work-schedules.edit', workSchedule.id)}>
                                                        Edit
                                                    </Link>
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => destroy(workSchedule)}
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
                <Pagination links={workSchedules.links} />
            </div>
        </AppLayout>
    );
}
