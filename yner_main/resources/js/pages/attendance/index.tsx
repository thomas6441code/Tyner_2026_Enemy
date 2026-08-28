import { Head, router } from '@inertiajs/react';
import {
    Ban,
    Calendar,
    CalendarX2,
    Check,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    CircleCheck,
    Clock,
    Download,
    FileSpreadsheet,
    FileText,
    type LucideIcon,
    Search,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';

import {
    AttendanceCorrectionDialog,
    type AttendanceCorrectionRecord,
} from '@/components/attendance-correction-dialog';
import { TablePagination } from '@/components/table-pagination';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePagination } from '@/hooks/use-pagination';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { route } from 'ziggy-js';

interface Cell {
    type: 'hours' | 'partial' | 'leave' | 'absent' | 'active' | null;
    label: string | null;
    record: AttendanceCorrectionRecord | null;
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
    iso: string;
}

interface StatusOption {
    value: string;
    label: string;
}

interface Stats {
    present: number;
    presentRemaining: number;
    late: number;
    onTime: number;
    onLeave: number;
    absent: number;
    /** Employee-days the range should have produced (employees x days). */
    expected: number;
    days: number;
    employees: number;
}

interface EmployeeOption {
    id: number;
    name: string;
}

interface Filters {
    from: string;
    to: string;
    employee_id: number | null;
}

interface AttendanceIndexProps {
    weekLabel: string;
    days: Day[];
    rows: Row[];
    stats: Stats;
    /** Whose records the cards count: everyone, one filtered employee, or just me. */
    statsScope: 'all' | 'employee' | 'mine';
    statusOptions: StatusOption[];
    canCorrect: boolean;
    canFilterEmployee: boolean;
    employees: EmployeeOption[];
    filters: Filters;
    exportUrls: { excel: string; pdf: string };
    status?: string;
}

/** The grid's only sortable axis is who the row is about — the rest of it is dates. */
type SortColumn = 'name' | 'role';

interface Correcting {
    record: AttendanceCorrectionRecord;
    employeeName: string;
    dayLabel: string;
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

const ALL_EMPLOYEES = 'all';

export default function AttendanceIndex({
    weekLabel,
    days,
    rows,
    stats,
    statsScope,
    statusOptions,
    canCorrect,
    canFilterEmployee,
    employees,
    filters,
    exportUrls,
    status,
}: AttendanceIndexProps) {
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<{ column: SortColumn; direction: 'asc' | 'desc' }>({
        column: 'name',
        direction: 'asc',
    });
    const [correcting, setCorrecting] = useState<Correcting | null>(null);
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [employeeId, setEmployeeId] = useState(
        filters.employee_id ? String(filters.employee_id) : ALL_EMPLOYEES,
    );

    // The KPI cards summarise the applied date range, not just today, so their labels have to
    // say which period and whose records they are counting.
    const singleDay = stats.days === 1;
    const today = new Date().toISOString().slice(0, 10);
    const periodLabel = singleDay ? (filters.from === today ? 'Today' : filters.from) : weekLabel;
    const scopeLabel =
        statsScope === 'mine' ? 'My attendance' : statsScope === 'employee' ? 'Selected employee' : 'All employees';
    const unit = singleDay ? 'People' : 'Days';

    // The grid ships every scoped employee in one payload, so search and sort are both resolved
    // here rather than round-tripping — the date range is the only thing the server re-queries.
    const filtered = rows.filter(
        (row) =>
            row.name.toLowerCase().includes(query.toLowerCase()) ||
            row.role.toLowerCase().includes(query.toLowerCase()),
    );

    const sorted = [...filtered].sort((a, b) => {
        const left = sort.column === 'role' ? a.role : a.name;
        const right = sort.column === 'role' ? b.role : b.name;
        const compared = left.localeCompare(right, undefined, { sensitivity: 'base' });

        return sort.direction === 'asc' ? compared : -compared;
    });

    const { page, pageSize, pageCount, paginated, total, setPage, setPageSize, reset } = usePagination(sorted);

    function handleQueryChange(value: string) {
        setQuery(value);
        reset();
    }

    function toggleSort(column: SortColumn) {
        setSort((current) =>
            current.column === column
                ? { column, direction: current.direction === 'asc' ? 'desc' : 'asc' }
                : { column, direction: 'asc' },
        );
        reset();
    }

    function applyFilters(e: React.FormEvent) {
        e.preventDefault();
        router.get(
            route('attendance.index'),
            {
                from,
                to,
                ...(canFilterEmployee && employeeId !== ALL_EMPLOYEES
                    ? { employee_id: Number(employeeId) }
                    : {}),
            },
            { preserveScroll: true, preserveState: true },
        );
    }

    function resetFilters() {
        router.get(route('attendance.index'), {}, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Employee Attendance" />

            {status && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">
                    {status}
                </div>
            )}

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Employee Attendance</h1>
                    <p className="text-sm text-muted-foreground">Analyse attendance records of employees</p>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button className="gap-2">
                            <Download className="h-4 w-4" /> Download
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            <a href={exportUrls.excel}>
                                <FileSpreadsheet className="mr-2 h-4 w-4 text-emerald-600" /> Excel (.csv)
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <a href={exportUrls.pdf}>
                                <FileText className="mr-2 h-4 w-4 text-rose-600" /> PDF (portrait)
                            </a>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <div className="mt-6 flex flex-wrap items-center gap-2 text-xs font-medium text-muted-foreground">
                <Users className="h-3.5 w-3.5" />
                <span>
                    <span className="text-foreground">{scopeLabel}</span> · {periodLabel}
                    {!singleDay && ` · ${stats.days} days × ${stats.employees} employee${stats.employees === 1 ? '' : 's'}`}
                </span>
            </div>

            <div className="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    icon={CircleCheck}
                    iconClass="bg-emerald-50 text-emerald-600"
                    label={singleDay ? `Present ${periodLabel}` : 'Present'}
                    value={stats.present}
                    caption={`${stats.presentRemaining} ${unit} Remaining of ${stats.expected}`}
                />
                <StatCard
                    icon={Clock}
                    iconClass="bg-amber-50 text-amber-600"
                    label="Late Entry"
                    value={stats.late}
                    caption={`${stats.onTime} ${unit} on Time`}
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
                <CardContent className="p-5">
                     <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
                        <div className="relative mt-7 min-w-0 flex-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                value={query}
                                onChange={(e) => handleQueryChange(e.target.value)}
                                placeholder="Search anything …"
                                className="h-9 w-full rounded-lg border border-input bg-muted/40 pl-9 pr-3 text-sm outline-none placeholder:text-muted-foreground focus:border-ring focus:bg-background focus:ring-1 focus:ring-ring"
                            />
                        </div>

                        <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-4">
                        <div className="w-40">
                            <Label htmlFor="from">From</Label>
                            <Input
                                id="from"
                                type="date"
                                value={from}
                                max={to || undefined}
                                className="mt-1"
                                onChange={(e) => setFrom(e.target.value)}
                            />
                        </div>
                        <div className="w-40">
                            <Label htmlFor="to">To</Label>
                            <Input
                                id="to"
                                type="date"
                                value={to}
                                min={from || undefined}
                                className="mt-1"
                                onChange={(e) => setTo(e.target.value)}
                            />
                        </div>

                        <Button type="submit">Apply</Button>
                        <Button type="button" variant="outline" onClick={resetFilters}>
                            Reset
                        </Button>
                        </form>
                    </div>

                </CardContent>
                <CardContent className="p-0">


                    <div className="overflow-x-auto p-2">
                        <table className="w-full min-w-[900px] border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    <th className="sticky left-0 z-10 bg-card px-3 py-3">
                                        <span className="inline-flex items-center gap-3">
                                            <button
                                                type="button"
                                                onClick={() => toggleSort('name')}
                                                aria-label="Sort by employee name"
                                                className={cn(
                                                    'inline-flex items-center gap-1 uppercase transition-colors hover:text-foreground',
                                                    sort.column === 'name' && 'text-foreground',
                                                )}
                                            >
                                                Employee
                                                {sort.column === 'name' ? (
                                                    sort.direction === 'asc' ? (
                                                        <ChevronUp className="h-3.5 w-3.5" />
                                                    ) : (
                                                        <ChevronDown className="h-3.5 w-3.5" />
                                                    )
                                                ) : (
                                                    <ChevronsUpDown className="h-3.5 w-3.5 opacity-40" />
                                                )}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => toggleSort('role')}
                                                aria-label="Sort by department"
                                                className={cn(
                                                    'inline-flex items-center gap-1 uppercase transition-colors hover:text-foreground',
                                                    sort.column === 'role' && 'text-foreground',
                                                )}
                                            >
                                                Dept
                                                {sort.column === 'role' ? (
                                                    sort.direction === 'asc' ? (
                                                        <ChevronUp className="h-3.5 w-3.5" />
                                                    ) : (
                                                        <ChevronDown className="h-3.5 w-3.5" />
                                                    )
                                                ) : (
                                                    <ChevronsUpDown className="h-3.5 w-3.5 opacity-40" />
                                                )}
                                            </button>
                                        </span>
                                    </th>
                                    {days.map((day) => (
                                        <th key={day.iso} className="px-3 py-3 font-semibold">
                                            {day.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {paginated.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={days.length + 1}
                                            className="px-3 py-10 text-center text-muted-foreground"
                                        >
                                            No employees match your search.
                                        </td>
                                    </tr>
                                ) : (
                                    paginated.map((row, index) => (
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
                                            {row.cells.map((cell, dayIndex) => {
                                                const day = days[dayIndex];
                                                const correctable = canCorrect && !!cell.record;

                                                return (
                                                    <td
                                                        key={dayIndex}
                                                        className="border-t border-border px-3 py-3 align-top group-hover:bg-muted/40"
                                                    >
                                                        <div className="mb-1.5 text-xs text-muted-foreground">
                                                            {day?.date}
                                                        </div>
                                                        {correctable ? (
                                                            <button
                                                                type="button"
                                                                title={`Correct ${row.name} — ${day?.name} ${day?.date}`}
                                                                className="rounded-md text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                                onClick={() =>
                                                                    setCorrecting({
                                                                        record: cell.record!,
                                                                        employeeName: row.name,
                                                                        dayLabel: `${day?.name} ${day?.date}`,
                                                                    })
                                                                }
                                                            >
                                                                <StatusPill cell={cell} />
                                                            </button>
                                                        ) : (
                                                            <StatusPill cell={cell} />
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    <TablePagination
                        page={page}
                        pageCount={pageCount}
                        pageSize={pageSize}
                        total={total}
                        onPageChange={setPage}
                        onPageSizeChange={setPageSize}
                        className="border-t border-border px-4 py-3"
                    />
                </CardContent>
            </Card>

            {correcting && (
                <AttendanceCorrectionDialog
                    record={correcting.record}
                    employeeName={correcting.employeeName}
                    dayLabel={correcting.dayLabel}
                    statusOptions={statusOptions}
                    onClose={() => setCorrecting(null)}
                />
            )}
        </AppLayout>
    );
}
