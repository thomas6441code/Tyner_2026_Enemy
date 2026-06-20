import { Head, router } from '@inertiajs/react';
import { CalendarClock, CalendarOff, Pencil, Plus, Timer, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { WorkScheduleFormDialog } from '@/components/work-schedule-form-dialog';
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
    stats: { schedules: number; employees: number; avgGrace: number; unused: number };
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function WorkSchedulesIndex({ workSchedules, stats, actions, status }: WorkSchedulesIndexProps) {
    const [dialog, setDialog] = useState<{ record: WorkSchedule | null } | null>(null);
    const [deleting, setDeleting] = useState<WorkSchedule | null>(null);

    return (
        <AppLayout>
            <Head title="Work Schedules" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Work Schedules</h1>
                    <p className="text-sm text-muted-foreground">Define working hours and grace periods</p>
                </div>
                {actions.create && (
                    <Button onClick={() => setDialog({ record: null })}>
                        <Plus className="h-4 w-4" /> New Work Schedule
                    </Button>
                )}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard icon={CalendarClock} iconClass="bg-blue-50 text-blue-600" label="Schedules" value={stats.schedules} />
                <StatCard icon={Users} iconClass="bg-emerald-50 text-emerald-600" label="Employees Assigned" value={stats.employees} />
                <StatCard icon={Timer} iconClass="bg-violet-50 text-violet-600" label="Avg Grace (min)" value={stats.avgGrace} />
                <StatCard icon={CalendarOff} iconClass="bg-amber-50 text-amber-600" label="Unused Schedules" value={stats.unused} />
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
                                    <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                                        No work schedules yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                workSchedules.data.map((workSchedule) => (
                                    <TableRow key={workSchedule.id}>
                                        <TableCell className="font-medium">{workSchedule.name}</TableCell>
                                        <TableCell>{workSchedule.start_time}</TableCell>
                                        <TableCell>{workSchedule.end_time}</TableCell>
                                        <TableCell>{workSchedule.grace_period_minutes}</TableCell>
                                        <TableCell>{workSchedule.employees_count}</TableCell>
                                        <TableCell className="text-right">
                                            {actions.update && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    onClick={() => setDialog({ record: workSchedule })}
                                                >
                                                    <Pencil className="h-4 w-4" /> Edit
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setDeleting(workSchedule)}
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
                <Pagination links={workSchedules.links} />
            </div>

            {dialog && <WorkScheduleFormDialog record={dialog.record} onClose={() => setDialog(null)} />}

            {deleting && (
                <ConfirmDialog
                    title="Delete work schedule"
                    description={`Delete "${deleting.name}"? This action cannot be undone.`}
                    onCancel={() => setDeleting(null)}
                    onConfirm={() =>
                        router.delete(route('work-schedules.destroy', deleting.id), {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
