import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    Clock,
    LogOut,
    type LucideIcon,
    MoreHorizontal,
    UserCheck,
    UserX,
    Umbrella,
    Users,
} from 'lucide-react';
import { route } from 'ziggy-js';

import { BarChart } from '@/components/charts/bar-chart';
import { LineAreaChart } from '@/components/charts/line-area-chart';
import { RadarChart } from '@/components/charts/radar-chart';
import { ChartCard, Donut, type DonutSegment, StatTile } from '@/components/dashboard/widgets';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

interface Series {
    labels: string[];
    values: number[];
    highlight: number;
}

interface DepartmentRow {
    id: number;
    name: string;
    employees_count: number;
}

interface PendingRequest {
    id: number;
    employee: string;
    type_label: string;
    start_date: string;
    end_date: string;
}

interface HrDashboardProps {
    monthLabel: string;
    stats: { total: number; active: number; inactive: number; unlinked: number; presentToday: number; pending: number };
    today: { checkedIn: number; notCheckedIn: number; onLeave: number; late: number; absent: number };
    donut: { onTime: number; late: number; leave: number; absent: number; notCheckedIn: number };
    attendanceTrend: Series;
    byDepartment: Series;
    weeklyAbsent: { label: string; value: number }[];
    requests: { pending: number; approved: number; rejected: number; recent: PendingRequest[] };
    exceptions: { lateComing: number; earlyGoing: number };
    departments: DepartmentRow[];
}

const DONUT_COLORS = {
    onTime: '#10b981',
    late: '#f59e0b',
    leave: '#0ea5e9',
    absent: '#f43f5e',
    notCheckedIn: '#94a3b8',
};

function pad(n: number) {
    return n.toString().padStart(2, '0');
}

function HeaderStat({ icon: Icon, value, label, highlight }: { icon: LucideIcon; value: number; label: string; highlight?: boolean }) {
    return (
        <div className={cn('relative flex items-center gap-4 rounded-xl p-5 shadow-card', highlight ? 'bg-primary text-slate-900' : 'bg-card text-card-foreground')}>
            <span className={cn('flex h-12 w-12 shrink-0 items-center justify-center rounded-xl', highlight ? 'bg-slate-900 text-white' : 'bg-primary/10 text-primary')}>
                <Icon className="h-5 w-5" />
            </span>
            <div>
                <div className="text-2xl font-bold leading-tight tracking-tight">{pad(value)}</div>
                <div className={cn('text-xs font-medium uppercase tracking-wide', highlight ? 'text-slate-900/70' : 'text-muted-foreground')}>{label}</div>
            </div>
        </div>
    );
}

function initials(name: string) {
    const parts = name.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

function formatRange(start: string, end: string) {
    const fmt = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    return start === end ? fmt(start) : `${fmt(start)} – ${fmt(end)}`;
}

export default function HrDashboard({
    monthLabel,
    stats,
    today,
    donut,
    attendanceTrend,
    byDepartment,
    weeklyAbsent,
    requests,
    exceptions,
    departments,
}: HrDashboardProps) {
    const { auth } = usePage<SharedData>().props;
    const firstName = auth.user?.name.split(' ')[0] ?? 'there';

    const segments: DonutSegment[] = [
        { label: 'On Time', value: donut.onTime, color: DONUT_COLORS.onTime },
        { label: 'Late', value: donut.late, color: DONUT_COLORS.late },
        { label: 'On Leave', value: donut.leave, color: DONUT_COLORS.leave },
        { label: 'Absent', value: donut.absent, color: DONUT_COLORS.absent },
        { label: 'Not In', value: donut.notCheckedIn, color: DONUT_COLORS.notCheckedIn },
    ];

    const maxDept = Math.max(...departments.map((d) => d.employees_count), 1);

    return (
        <AppLayout>
            <Head title="HR Dashboard" />

            {/* Greeting header + workforce KPIs */}
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
                            <div className="text-sm text-slate-300">Workforce overview for {monthLabel}.</div>
                        </div>
                    </div>
                    {stats.pending > 0 && (
                        <Link
                            href={route('permission-requests.index')}
                            className="flex items-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-primary/90"
                        >
                            {stats.pending} pending request{stats.pending === 1 ? '' : 's'} <ArrowRight className="h-4 w-4" />
                        </Link>
                    )}
                </div>

                <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <HeaderStat icon={Users} value={stats.total} label="Total Employees" />
                    <HeaderStat icon={UserCheck} value={stats.active} label="Active" highlight />
                    <HeaderStat icon={CheckCircle2} value={stats.presentToday} label="Present Today" />
                    <HeaderStat icon={CalendarClock} value={stats.pending} label="Pending Requests" />
                </div>
            </div>

            {/* Statistics donut + today's attendance tiles */}
            <div className="mt-6 grid gap-4 lg:grid-cols-3">
                <ChartCard title="Today's Composition">
                    <Donut segments={segments} total={stats.total} caption="Staff" />
                </ChartCard>

                <Card className="lg:col-span-2">
                    <CardContent className="p-5">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-sm font-semibold">Attendance Today</h2>
                            <span className="text-xs text-muted-foreground">{stats.total} staff</span>
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <StatTile icon={CheckCircle2} label="Checked In" value={today.checkedIn} tone="emerald" />
                            <StatTile icon={CalendarClock} label="Not Checked In" value={today.notCheckedIn} tone="slate" />
                            <StatTile icon={Umbrella} label="On Leave" value={today.onLeave} tone="sky" />
                            <StatTile icon={Clock} label="Late" value={today.late} tone="amber" />
                            <StatTile icon={UserX} label="Absent" value={today.absent} tone="rose" />
                            <StatTile icon={Users} label="Unlinked" value={stats.unlinked} tone="indigo" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Trend line + department distribution */}
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <ChartCard title="Attendance Trend (30 days)" className="lg:col-span-2">
                    <LineAreaChart values={attendanceTrend.values} labels={attendanceTrend.labels} highlightIndex={attendanceTrend.highlight} />
                </ChartCard>

                <ChartCard title="Employees by Department">
                    {byDepartment.values.length > 0 ? (
                        <BarChart values={byDepartment.values} labels={byDepartment.labels} highlightIndex={byDepartment.highlight} />
                    ) : (
                        <p className="py-10 text-center text-sm text-muted-foreground">No departments yet.</p>
                    )}
                </ChartCard>
            </div>

            {/* Pending queue + weekly absence pattern */}
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <ChartCard
                    title="Pending Permission Requests"
                    className="lg:col-span-2"
                    action={
                        <Link href={route('permission-requests.index')} className="text-xs font-medium text-primary hover:underline">
                            Review all
                        </Link>
                    }
                >
                    {requests.recent.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">No pending requests. You're all caught up. 🎉</p>
                    ) : (
                        <ul className="space-y-3">
                            {requests.recent.map((req) => (
                                <li key={req.id} className="flex items-center gap-3">
                                    <Avatar className="h-8 w-8">
                                        <AvatarFallback className="bg-primary/10 text-[10px] font-semibold text-primary">
                                            {initials(req.employee)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-medium">{req.employee}</div>
                                        <div className="text-xs text-muted-foreground">{formatRange(req.start_date, req.end_date)}</div>
                                    </div>
                                    <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                                        {req.type_label}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </ChartCard>

                <ChartCard title="Weekly Absent">
                    <RadarChart data={weeklyAbsent} />
                </ChartCard>
            </div>

            {/* Request throughput + exceptions */}
            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <ChartCard title={`Requests · ${monthLabel}`}>
                    <div className="grid grid-cols-3 gap-3">
                        <StatTile icon={CalendarClock} label="Pending" value={requests.pending} tone="indigo" />
                        <StatTile icon={CheckCircle2} label="Approved" value={requests.approved} tone="emerald" />
                        <StatTile icon={UserX} label="Rejected" value={requests.rejected} tone="rose" />
                    </div>
                </ChartCard>

                <ChartCard title={`Exceptions · ${monthLabel}`}>
                    <div className="grid grid-cols-2 gap-3">
                        <StatTile icon={Clock} label="Late Coming" value={exceptions.lateComing} tone="amber" />
                        <StatTile icon={LogOut} label="Early Going" value={exceptions.earlyGoing} tone="rose" />
                    </div>
                </ChartCard>
            </div>

            {/* Departments table */}
            <Card className="mt-4">
                <CardContent className="p-5">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold">Departments</h2>
                        <button type="button" className="text-muted-foreground hover:text-foreground" aria-label="Options">
                            <MoreHorizontal className="h-4 w-4" />
                        </button>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Department</TableHead>
                                <TableHead className="w-48">Distribution</TableHead>
                                <TableHead className="w-24 text-right">Employees</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {departments.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={3} className="py-10 text-center text-muted-foreground">
                                        No departments yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                departments.map((department) => (
                                    <TableRow key={department.id}>
                                        <TableCell className="font-medium">{department.name}</TableCell>
                                        <TableCell>
                                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary"
                                                    style={{ width: `${Math.round((department.employees_count / maxDept) * 100)}%` }}
                                                />
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{department.employees_count}</TableCell>
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
