import { Head } from '@inertiajs/react';
import { CheckCircle2, Flag, ShieldCheck, ShieldOff, Smartphone, XCircle } from 'lucide-react';
import { useState } from 'react';

import { Pagination } from '@/components/pagination';
import { SortableHead } from '@/components/sortable-head';
import { SearchInput, TableToolbar, nextDirection, visitIndex, type IndexFilters } from '@/components/table-toolbar';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface CheckInRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    work_date: string;
    punched_at: string;
    direction: string;
    direction_label: string;
    location: string | null;
    distance_meters: number | null;
    accuracy_meters: number | null;
    within_geofence: boolean;
    webauthn_verified: boolean;
    device_name: string | null;
    result: string;
    result_label: string;
    rejection_reason: string | null;
    rejection_label: string | null;
    flagged: boolean;
    flag_reason: string | null;
    ip_address: string | null;
}

interface Filters extends IndexFilters {
    from: string | null;
    to: string | null;
    employee: number | null;
    result: string | null;
    flagged: boolean;
}

interface MobileCheckInsIndexProps {
    checkIns: { data: CheckInRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Filters;
    employees: { id: number; name: string; employee_code: string }[];
    results: { value: string; label: string }[];
    stats: { today: number; accepted_today: number; rejected_today: number; flagged: number };
}

export default function MobileCheckInsIndex({ checkIns, filters, employees, results, stats }: MobileCheckInsIndexProps) {
    const [form, setForm] = useState({
        from: filters.from ?? '',
        to: filters.to ?? '',
        employee: filters.employee ? String(filters.employee) : '',
        result: filters.result ?? '',
        flagged: filters.flagged,
    });

    // The dropdown filters and the free-text search share one query string, so changing either
    // one has to carry the other along rather than silently dropping it.
    const apply = (next: typeof form, patch: Record<string, unknown> = {}) => {
        setForm(next);

        visitIndex('mobile-check-ins.index', {
            from: next.from,
            to: next.to,
            employee: next.employee,
            result: next.result,
            flagged: next.flagged ? 1 : undefined,
            search: filters.search,
            sort: filters.sort,
            direction: filters.direction,
            ...patch,
        });
    };

    const applySort = (column: string) =>
        apply(form, { sort: column, direction: nextDirection(column, filters.sort, filters.direction) });

    const reset = () =>
        apply(
            { from: '', to: '', employee: '', result: '', flagged: false },
            { search: undefined, sort: undefined, direction: undefined },
        );

    return (
        <AppLayout>
            <Head title="Mobile Check-Ins" />

            <div>
                <h1 className="text-2xl font-bold tracking-tight">Mobile Check-Ins</h1>
                <p className="text-sm text-muted-foreground">
                    Every attempt from the mobile channel, accepted or refused, with the distance the
                    server measured
                </p>
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard icon={Smartphone} iconClass="bg-blue-50 text-blue-600" label="Attempts Today" value={stats.today} />
                <StatCard icon={CheckCircle2} iconClass="bg-emerald-50 text-emerald-600" label="Accepted Today" value={stats.accepted_today} />
                <StatCard icon={XCircle} iconClass="bg-rose-50 text-rose-600" label="Refused Today" value={stats.rejected_today} />
                <StatCard icon={Flag} iconClass="bg-amber-50 text-amber-600" label="Flagged" value={stats.flagged} />
            </div>

            <Card className="mt-6">
                <CardContent className="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={form.from} onChange={(e) => apply({ ...form, from: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={form.to} onChange={(e) => apply({ ...form, to: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="employee">Employee</Label>
                        <select
                            id="employee"
                            value={form.employee}
                            onChange={(e) => apply({ ...form, employee: e.target.value })}
                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All employees</option>
                            {employees.map((employee) => (
                                <option key={employee.id} value={employee.id}>
                                    {employee.name} ({employee.employee_code})
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="result">Result</Label>
                        <select
                            id="result"
                            value={form.result}
                            onChange={(e) => apply({ ...form, result: e.target.value })}
                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All results</option>
                            {results.map((result) => (
                                <option key={result.value} value={result.value}>
                                    {result.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex items-end gap-2">
                        <Button
                            type="button"
                            variant={form.flagged ? 'default' : 'outline'}
                            onClick={() => apply({ ...form, flagged: !form.flagged })}
                        >
                            <Flag className="h-4 w-4" /> Flagged
                        </Button>
                        <Button type="button" variant="ghost" onClick={reset}>
                            Reset
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card className="mt-4">
                <CardContent className="p-0">
                    <TableToolbar>
                        <SearchInput
                            value={filters.search}
                            onSearch={(search) => apply(form, { search })}
                            placeholder="Search employee, code, device, location or IP…"
                            className="min-w-0 flex-1"
                        />
                    </TableToolbar>
                    <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead column="employee" label="Employee" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                <SortableHead column="punched_at" label="When" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                <SortableHead column="direction" label="Direction" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                <TableHead>Location</TableHead>
                                <SortableHead column="distance_meters" label="Distance" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                                <TableHead>Device</TableHead>
                                <SortableHead column="result" label="Result" sort={filters.sort} direction={filters.direction} onSort={applySort} />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {checkIns.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="py-10 text-center text-muted-foreground">
                                        No mobile check-ins match these filters.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                checkIns.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <div className="font-medium">{row.employee ?? '—'}</div>
                                            <div className="text-xs text-muted-foreground">{row.employee_code}</div>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {row.punched_at}
                                            {row.ip_address && <div className="text-xs">{row.ip_address}</div>}
                                        </TableCell>
                                        <TableCell>{row.direction_label}</TableCell>
                                        <TableCell className="text-muted-foreground">{row.location ?? '—'}</TableCell>
                                        <TableCell>
                                            <span className={row.within_geofence ? '' : 'font-medium text-rose-700'}>
                                                {row.distance_meters === null ? '—' : `${row.distance_meters} m`}
                                            </span>
                                            {row.accuracy_meters !== null && (
                                                <div className="text-xs text-muted-foreground">
                                                    ±{row.accuracy_meters} m
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1 text-sm">
                                                {row.webauthn_verified ? (
                                                    <ShieldCheck className="h-4 w-4 text-emerald-600" />
                                                ) : (
                                                    <ShieldOff className="h-4 w-4 text-muted-foreground" />
                                                )}
                                                {row.device_name ?? '—'}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={
                                                    row.result === 'accepted'
                                                        ? 'rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700'
                                                        : 'rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700'
                                                }
                                            >
                                                {row.result_label}
                                            </span>
                                            {row.rejection_label && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {row.rejection_label}
                                                </div>
                                            )}
                                            {row.flagged && (
                                                <div className="mt-1 flex items-center gap-1 text-xs text-amber-700">
                                                    <Flag className="h-3 w-3" />
                                                    {row.flag_reason ?? 'Flagged'}
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                    </div>
                </CardContent>
            </Card>

            <div className="mt-3">
                <Pagination links={checkIns.links} />
            </div>
        </AppLayout>
    );
}
