import { Head } from '@inertiajs/react';
import {
    Ban,
    Calendar,
    CalendarX2,
    Check,
    CircleCheck,
    Clock,
    Download,
    type LucideIcon,
    Search,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { useState } from 'react';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

interface Cell {
    type: 'hours' | 'partial' | 'leave' | 'absent' | 'active' | null;
    label: string | null;
}

interface Row {
    id: number;
    name: string;
    role: string;
    initials: string;
    cells: Cell[];
}

interface Day {
    name: string;
    date: number;
}

interface Stats {
    present: number;
    presentRemaining: number;
    late: number;
    onTime: number;
    onLeave: number;
    absent: number;
}

interface AttendanceIndexProps {
    weekLabel: string;
    days: Day[];
    rows: Row[];
    stats: Stats;
}

const AVATAR_TONES = [
    'bg-blue-100 text-blue-700',
    'bg-emerald-100 text-emerald-700',
    'bg-amber-100 text-amber-700',
    'bg-violet-100 text-violet-700',
    'bg-rose-100 text-rose-700',
    'bg-cyan-100 text-cyan-700',
];

function pad(n: number) {
    return n.toString().padStart(2, '0');
}

function StatusPill({ cell }: { cell: Cell }) {
    if (!cell.type) {
        return null;
    }

    const styles: Record<NonNullable<Cell['type']>, { className: string; icon: LucideIcon }> = {
        hours: { className: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', icon: CircleCheck },
        active: { className: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', icon: Check },
        partial: { className: 'bg-amber-50 text-amber-700 ring-amber-600/20', icon: Clock },
        leave: { className: 'bg-violet-50 text-violet-700 ring-violet-600/20', icon: CalendarX2 },
        absent: { className: 'bg-rose-50 text-rose-700 ring-rose-600/20', icon: X },
    };

    const { className, icon: Icon } = styles[cell.type];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                className,
            )}
        >
            <Icon className="h-3 w-3" />
            {cell.label}
        </span>
    );
}

interface StatCardProps {
    icon: LucideIcon;
    iconClass: string;
    label: string;
    value: number;
    caption: string;
}

function StatCard({ icon: Icon, iconClass, label, value, caption }: StatCardProps) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-3 p-5">
                <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                    <span className={cn('flex h-7 w-7 items-center justify-center rounded-lg', iconClass)}>
                        <Icon className="h-4 w-4" />
                    </span>
                    {label}
                </div>
                <div className="text-3xl font-bold tracking-tight">{pad(value)}</div>
                <div className="text-xs text-muted-foreground">{caption}</div>
            </CardContent>
        </Card>
    );
}

export default function AttendanceIndex({ weekLabel, days, rows, stats }: AttendanceIndexProps) {
    const [chips, setChips] = useState(['Leave', 'Absent', 'Active']);
    const [query, setQuery] = useState('');

    const filtered = rows.filter(
        (row) =>
            row.name.toLowerCase().includes(query.toLowerCase()) ||
            row.role.toLowerCase().includes(query.toLowerCase()),
    );

    return (
        <AppLayout>
            <Head title="Employee Attendance" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Employee Attendance</h1>
                    <p className="text-sm text-muted-foreground">Analyse attendance records of employees</p>
                </div>
                <Button>
                    <Download className="h-4 w-4" /> Download
                </Button>
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    icon={CircleCheck}
                    iconClass="bg-emerald-50 text-emerald-600"
                    label="Present Today"
                    value={stats.present}
                    caption={`${stats.presentRemaining} People Remaining`}
                />
                <StatCard
                    icon={Clock}
                    iconClass="bg-amber-50 text-amber-600"
                    label="Late Entry"
                    value={stats.late}
                    caption={`${stats.onTime} People are on Time`}
                />
                <StatCard
                    icon={CalendarX2}
                    iconClass="bg-violet-50 text-violet-600"
                    label="On Leave"
                    value={stats.onLeave}
                    caption="Approved Leave"
                />
                <StatCard
                    icon={Ban}
                    iconClass="bg-rose-50 text-rose-600"
                    label="Absent"
                    value={stats.absent}
                    caption="Without Informing"
                />
            </div>

            <Card className="mt-6">
                <CardContent className="p-0">
                    <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Search anything …"
                                className="h-9 w-full rounded-lg border border-input bg-muted/40 pl-9 pr-3 text-sm outline-none placeholder:text-muted-foreground focus:border-ring focus:bg-background focus:ring-1 focus:ring-ring"
                            />
                        </div>
                        <Button variant="outline" size="sm" className="gap-2">
                            <SlidersHorizontal className="h-4 w-4" /> Filter
                        </Button>
                        <Button variant="outline" size="sm" className="gap-2 font-normal">
                            <Calendar className="h-4 w-4" /> {weekLabel}
                        </Button>
                    </div>

                    {chips.length > 0 && (
                        <div className="flex flex-wrap items-center gap-2 px-4 pt-4">
                            {chips.map((chip) => (
                                <span
                                    key={chip}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-muted px-2.5 py-1 text-xs font-medium text-foreground/80"
                                >
                                    {chip}
                                    <button
                                        type="button"
                                        onClick={() => setChips((c) => c.filter((x) => x !== chip))}
                                        className="text-muted-foreground hover:text-foreground"
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                </span>
                            ))}
                        </div>
                    )}

                    <div className="overflow-x-auto p-2">
                        <table className="w-full min-w-[900px] border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    <th className="sticky left-0 z-10 bg-card px-3 py-3">Employee</th>
                                    {days.map((day) => (
                                        <th key={day.name} className="px-3 py-3 font-semibold">
                                            {day.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={days.length + 1}
                                            className="px-3 py-10 text-center text-muted-foreground"
                                        >
                                            No employees match your search.
                                        </td>
                                    </tr>
                                ) : (
                                    filtered.map((row, index) => (
                                        <tr key={row.id} className="group">
                                            <td className="sticky left-0 z-10 border-t border-border bg-card px-3 py-3 group-hover:bg-muted/40">
                                                <div className="flex items-center gap-3">
                                                    <Avatar className="h-9 w-9">
                                                        <AvatarFallback
                                                            className={cn(
                                                                'text-xs font-semibold',
                                                                AVATAR_TONES[index % AVATAR_TONES.length],
                                                            )}
                                                        >
                                                            {row.initials}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <div className="leading-tight">
                                                        <div className="font-medium text-foreground">{row.name}</div>
                                                        <div className="text-xs text-muted-foreground">{row.role}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            {row.cells.map((cell, dayIndex) => (
                                                <td
                                                    key={dayIndex}
                                                    className="border-t border-border px-3 py-3 align-top group-hover:bg-muted/40"
                                                >
                                                    <div className="mb-1.5 text-xs text-muted-foreground">
                                                        {days[dayIndex]?.date}
                                                    </div>
                                                    <StatusPill cell={cell} />
                                                </td>
                                            ))}
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
