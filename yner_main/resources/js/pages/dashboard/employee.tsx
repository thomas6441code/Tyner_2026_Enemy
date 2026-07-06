import { Head, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    Clock,
    FileClock,
    Hourglass,
    LogIn,
    LogOut,
    type LucideIcon,
    Umbrella,
    UserX,
} from 'lucide-react';

import { BarChart } from '@/components/charts/bar-chart';
import { ChartCard, DetailTile, Donut, type DonutSegment, StatTile, type Tone } from '@/components/dashboard/widgets';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';

interface EmployeeDashboardProps {
    employee: {
        name: string;
        employee_code: string;
        status: string;
        department: { name: string } | null;
        workSchedule: { name: string } | null;
    } | null;
    monthLabel?: string;
    summary?: {
        present: number;
        late: number;
        absent: number;
        leave: number;
        workedHours: number;
        totalDays: number;
    };
    today?: { status: string; label: string };
    week?: { labels: string[]; hours: number[]; late: number[]; todayIndex: number };
    exceptions?: { lateComing: number; earlyGoing: number };
    requests?: { pending: number; thisMonth: number; approved: number };
}

const DONUT_COLORS = {
    present: '#10b981',
    late: '#f59e0b',
    leave: '#0ea5e9',
    absent: '#f43f5e',
};

function TodayTile({ today }: { today: { status: string; label: string } }) {
    const map: Record<string, { icon: LucideIcon; tone: Tone }> = {
        checked_in: { icon: LogIn, tone: 'emerald' },
        checked_out: { icon: LogOut, tone: 'indigo' },
        on_leave: { icon: Umbrella, tone: 'sky' },
        absent: { icon: UserX, tone: 'rose' },
        none: { icon: CalendarClock, tone: 'slate' },
    };
    const { icon, tone } = map[today.status] ?? map.none;

    return <StatTile icon={icon} label="Today" value={today.label} tone={tone} />;
}

export default function EmployeeDashboard({ employee, monthLabel, summary, today, week, exceptions, requests }: EmployeeDashboardProps) {
    const { auth } = usePage<SharedData>().props;
    const firstName = auth.user?.name.split(' ')[0] ?? 'there';

    if (!employee || !summary || !today || !week || !exceptions || !requests) {
        return (
            <AppLayout>
                <Head title="My Dashboard" />
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">My Dashboard</h1>
                    <p className="text-sm text-muted-foreground">Your employment details</p>
                </div>
                <div className="mt-6 max-w-xl rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
                    No employee record is linked to your account yet. Contact HR to get set up.
                </div>
            </AppLayout>
        );
    }

    const segments: DonutSegment[] = [
        { label: 'Present', value: summary.present, color: DONUT_COLORS.present },
        { label: 'Late', value: summary.late, color: DONUT_COLORS.late },
        { label: 'On Leave', value: summary.leave, color: DONUT_COLORS.leave },
        { label: 'Absent', value: summary.absent, color: DONUT_COLORS.absent },
    ];

    return (
        <AppLayout>
            <Head title="My Dashboard" />

            {/* Greeting header */}
            <div className="rounded-2xl bg-slate-900 p-6 text-white">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Avatar className="h-11 w-11">
                            <AvatarFallback className="bg-primary text-base font-semibold text-slate-900">
                                {firstName.charAt(0).toUpperCase()}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <div className="text-lg font-semibold">Hello {firstName}! 👋</div>
                            <div className="text-sm text-slate-300">Here's your attendance for {monthLabel}.</div>
                        </div>
                    </div>
                    <Badge variant={employee.status === 'active' ? 'success' : 'secondary'} className="text-xs">
                        {employee.status.charAt(0).toUpperCase() + employee.status.slice(1)}
                    </Badge>
                </div>
            </div>

            {/* Statistics donut + Attendance tiles */}
            <div className="mt-6 grid gap-4 lg:grid-cols-3">
                <ChartCard title="Statistics">
                    <Donut segments={segments} total={summary.totalDays} caption="Days Logged" />
                </ChartCard>

                <Card className="lg:col-span-2">
                    <CardContent className="p-5">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-sm font-semibold">Attendance</h2>
                            <span className="text-xs text-muted-foreground">{monthLabel}</span>
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <StatTile icon={CheckCircle2} label="Present" value={summary.present} tone="emerald" />
                            <StatTile icon={Clock} label="Late" value={summary.late} tone="amber" />
                            <StatTile icon={UserX} label="Absent" value={summary.absent} tone="rose" />
                            <StatTile icon={Umbrella} label="On Leave" value={summary.leave} tone="sky" />
                            <StatTile icon={Hourglass} label="Worked Hours" value={`${summary.workedHours}h`} tone="indigo" />
                            <TodayTile today={today} />
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Weekly charts + employment details */}
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <ChartCard title="This Week — Hours">
                    <BarChart values={week.hours} labels={week.labels} highlightIndex={week.todayIndex} />
                </ChartCard>

                <ChartCard title="Late Minutes This Week">
                    <BarChart values={week.late} labels={week.labels} highlightIndex={week.todayIndex} />
                </ChartCard>

                <ChartCard title="My Details">
                    <div className="grid grid-cols-2 gap-3">
                        <DetailTile label="Employee Code" value={employee.employee_code} />
                        <DetailTile label="Department" value={employee.department?.name ?? '—'} />
                        <DetailTile label="Work Schedule" value={employee.workSchedule?.name ?? '—'} />
                        <DetailTile
                            label="Status"
                            value={employee.status.charAt(0).toUpperCase() + employee.status.slice(1)}
                        />
                    </div>
                </ChartCard>
            </div>

            {/* Exceptions + Requests */}
            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <ChartCard title="Exceptions">
                    <div className="grid grid-cols-2 gap-3">
                        <StatTile icon={Clock} label="Late Coming" value={exceptions.lateComing} tone="amber" />
                        <StatTile icon={LogOut} label="Early Going" value={exceptions.earlyGoing} tone="rose" />
                    </div>
                </ChartCard>

                <ChartCard title="Requests">
                    <div className="grid grid-cols-2 gap-3">
                        <StatTile icon={FileClock} label="Pending" value={requests.pending} tone="indigo" />
                        <StatTile icon={CheckCircle2} label="Approved · This Month" value={requests.approved} tone="emerald" />
                    </div>
                </ChartCard>
            </div>
        </AppLayout>
    );
}
