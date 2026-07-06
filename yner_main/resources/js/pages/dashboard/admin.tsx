import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Brain,
    Calendar,
    ChevronDown,
    Clock,
    LogIn,
    type LucideIcon,
    MoreHorizontal,
    SlidersHorizontal,
    UserX,
    Users,
} from 'lucide-react';
import { ReactNode } from 'react';
import { route } from 'ziggy-js';

import { BarChart } from '@/components/charts/bar-chart';
import { LineAreaChart } from '@/components/charts/line-area-chart';
import { RadarChart } from '@/components/charts/radar-chart';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

interface Series {
    labels: string[];
    values: number[];
    highlight: number;
}

interface AdminDashboardProps {
    stats: { total: number; present: number; absent: number; late: number };
    attendanceReport: Series;
    byDepartment: Series;
    byLogin: { linked: number; unlinked: number };
    topAttendants: { name: string; initials: string; percent: number; days: number }[];
    weeklyAbsent: { label: string; value: number }[];
    decisionSupport: {
        highRisk: { name: string; initials: string; risk: number }[];
        anomalies: number;
    };
}

function pad(n: number) {
    return n.toString().padStart(2, '0');
}

interface StatCardProps {
    icon: LucideIcon;
    value: number;
    label: string;
    highlight?: boolean;
}

function StatCard({ icon: Icon, value, label, highlight }: StatCardProps) {
    return (
        <div
            className={cn(
                'relative flex items-center gap-4 rounded-xl p-5 shadow-card',
                highlight ? 'bg-primary text-slate-900' : 'bg-card text-card-foreground',
            )}
        >
            <span
                className={cn(
                    'flex h-12 w-12 shrink-0 items-center justify-center rounded-xl',
                    highlight ? 'bg-slate-900 text-white' : 'bg-primary/10 text-primary',
                )}
            >
                <Icon className="h-5 w-5" />
            </span>
            <div>
                <div className="text-2xl font-bold leading-tight tracking-tight">{pad(value)}</div>
                <div className={cn('text-xs font-medium uppercase tracking-wide', highlight ? 'text-slate-900/70' : 'text-muted-foreground')}>
                    {label}
                </div>
            </div>
            <button
                type="button"
                className={cn(
                    'absolute right-3 top-3 rounded-md p-1',
                    highlight ? 'text-slate-900/60 hover:text-slate-900' : 'text-muted-foreground hover:text-foreground',
                )}
                aria-label="Card options"
            >
                <MoreHorizontal className="h-4 w-4" />
            </button>
        </div>
    );
}

function ChartCard({ title, className, children }: { title: string; className?: string; children: ReactNode }) {
    return (
        <Card className={className}>
            <CardContent className="p-5">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    <button type="button" className="text-muted-foreground hover:text-foreground" aria-label="Options">
                        <MoreHorizontal className="h-4 w-4" />
                    </button>
                </div>
                {children}
            </CardContent>
        </Card>
    );
}

export default function AdminDashboard({
    stats,
    attendanceReport,
    byDepartment,
    byLogin,
    topAttendants,
    weeklyAbsent,
    decisionSupport,
}: AdminDashboardProps) {
    const { auth } = usePage<SharedData>().props;
    const firstName = auth.user?.name.split(' ')[0] ?? 'there';

    const loginTotal = byLogin.linked + byLogin.unlinked || 1;
    const linkedPct = Math.round((byLogin.linked / loginTotal) * 100);
    const unlinkedPct = 100 - linkedPct;

    return (
        <AppLayout>
            <Head title="Dashboard" />

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
                            <div className="text-sm text-slate-300">We hope you're having a great day.</div>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-white hover:bg-white/15"
                        >
                            All Classes <ChevronDown className="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-white hover:bg-white/15"
                        >
                            <Calendar className="h-4 w-4" /> Last 30 days
                        </button>
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-primary/90"
                        >
                            <SlidersHorizontal className="h-4 w-4" /> Filter
                        </button>
                    </div>
                </div>

                <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard icon={Users} value={stats.total} label="Total Employees" />
                    <StatCard icon={LogIn} value={stats.present} label="Present Today" highlight />
                    <StatCard icon={UserX} value={stats.absent} label="Absent Today" />
                    <StatCard icon={Clock} value={stats.late} label="Late Today" />
                </div>
            </div>

            <div className="mt-6 grid gap-4 lg:grid-cols-3">
                <ChartCard title="Total Attendance Report" className="lg:col-span-2">
                    <LineAreaChart
                        values={attendanceReport.values}
                        labels={attendanceReport.labels}
                        highlightIndex={attendanceReport.highlight}
                    />
                </ChartCard>

                <ChartCard title="Employees by Department">
                    {byDepartment.values.length > 0 ? (
                        <BarChart
                            values={byDepartment.values}
                            labels={byDepartment.labels}
                            highlightIndex={byDepartment.highlight}
                        />
                    ) : (
                        <p className="py-10 text-center text-sm text-muted-foreground">No departments yet.</p>
                    )}
                </ChartCard>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <ChartCard title="Employees by Login">
                    <div className="relative mx-auto mt-2 h-44 w-64">
                        <div className="absolute left-0 top-1/2 flex h-40 w-40 -translate-y-1/2 items-center justify-center rounded-full bg-primary text-2xl font-bold text-slate-900">
                            {linkedPct}%
                        </div>
                        <div className="absolute right-0 top-1/2 flex h-32 w-32 -translate-y-1/2 items-center justify-center rounded-full bg-slate-900 text-xl font-bold text-white ring-1 ring-white/10">
                            {unlinkedPct}%
                        </div>
                    </div>
                    <div className="mt-4 flex items-center justify-center gap-6 text-sm">
                        <span className="flex items-center gap-2">
                            <span className="h-3 w-3 rounded-full bg-primary" /> Linked {byLogin.linked}
                        </span>
                        <span className="flex items-center gap-2">
                            <span className="h-3 w-3 rounded-full bg-slate-900" /> No Login {byLogin.unlinked}
                        </span>
                    </div>
                </ChartCard>

                <ChartCard title="Top 6 Attendant">
                    <ul className="space-y-3">
                        {topAttendants.length === 0 && (
                            <li className="py-8 text-center text-sm text-muted-foreground">No employees yet.</li>
                        )}
                        {topAttendants.map((person) => (
                            <li key={person.name} className="flex items-center gap-3">
                                <Avatar className="h-8 w-8">
                                    <AvatarFallback className="bg-primary/10 text-[10px] font-semibold text-primary">
                                        {person.initials}
                                    </AvatarFallback>
                                </Avatar>
                                <span className="min-w-0 flex-1 truncate text-sm font-medium">{person.name}</span>
                                <div className="flex w-24 items-center gap-2">
                                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                        <div className="h-full rounded-full bg-primary" style={{ width: `${person.percent}%` }} />
                                    </div>
                                    <span className="w-8 text-right text-xs text-muted-foreground">{person.percent}%</span>
                                </div>
                                <span className="w-14 text-right text-xs text-muted-foreground">{person.days} days</span>
                            </li>
                        ))}
                    </ul>
                </ChartCard>

                <ChartCard title="Weekly Absent">
                    <RadarChart data={weeklyAbsent} />
                </ChartCard>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardContent className="p-5">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <Brain className="h-4 w-4 text-primary" /> AI Decision Support
                            </h2>
                            <Link
                                href={route('ai-insights.index')}
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                View AI Insights
                            </Link>
                        </div>
                        {decisionSupport.highRisk.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No high-risk employees flagged. Run attendance scoring to populate this.
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {decisionSupport.highRisk.map((person) => (
                                    <li key={person.name} className="flex items-center gap-3">
                                        <Avatar className="h-8 w-8">
                                            <AvatarFallback className="bg-rose-100 text-[10px] font-semibold text-rose-700">
                                                {person.initials}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span className="min-w-0 flex-1 truncate text-sm font-medium">{person.name}</span>
                                        <div className="flex w-28 items-center gap-2">
                                            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-rose-500"
                                                    style={{ width: `${person.risk}%` }}
                                                />
                                            </div>
                                            <span className="w-10 text-right text-xs text-muted-foreground">
                                                {person.risk}%
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <ChartCard title="Anomalies (30 days)">
                    <div className="flex flex-col items-center justify-center py-6">
                        <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-100 text-amber-700">
                            <AlertTriangle className="h-7 w-7" />
                        </span>
                        <div className="mt-3 text-3xl font-bold tracking-tight">{decisionSupport.anomalies}</div>
                        <div className="text-sm text-muted-foreground">detected anomalies</div>
                    </div>
                </ChartCard>
            </div>
        </AppLayout>
    );
}
