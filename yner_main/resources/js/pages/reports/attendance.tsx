import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    CalendarClock,
    Clock,
    Download,
    FileText,
    Sparkles,
    UserX,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { BarChart } from '@/components/charts/bar-chart';
import { LineAreaChart } from '@/components/charts/line-area-chart';
import { StatCard } from '@/components/stat-card';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePagination } from '@/hooks/use-pagination';

import AppLayout from '@/layouts/app-layout';

interface Series {
    labels: string[];
    values: number[];
    highlight: number;
}

interface ReportRow {
    id: number;
    employee_code: string;
    name: string;
    department: string;
    present: number;
    late: number;
    absent: number;
    leave: number;
    worked_hours: number;
    late_minutes: number;
    attendance_rate: number;
    punctuality_rate: number;
}

interface Totals {
    employees: number;
    present: number;
    late: number;
    absent: number;
    leave: number;
    attendance_rate: number;
    punctuality_rate: number;
}

interface ReportProps {
    report: {
        meta: {
            from_label: string;
            to_label: string;
            scope: string;
            employees: number;
            working_days: number;
        };
        rows: ReportRow[];
        totals: Totals;
        trend: Series;
        statusDistribution: Series;
    };
    departments: { id: number; name: string }[];
    filters: { from: string; to: string; department_id: number | null };
    decisionSupport: {
        highRisk: { employee: string; risk_score: number }[];
        summary: { period_label: string; narrative: string } | null;
    };
    exportUrls: { csv: string; pdf: string };
}

const ALL = 'all';

export default function AttendanceReport({
    report,
    departments,
    filters,
    decisionSupport,
    exportUrls,
}: ReportProps) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [departmentId, setDepartmentId] = useState(filters.department_id ? String(filters.department_id) : ALL);

    const apply = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            route('reports.index'),
            {
                from,
                to,
                ...(departmentId !== ALL ? { department_id: Number(departmentId) } : {}),
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    const { meta, totals, rows, trend, statusDistribution } = report;

    const { page, pageSize, pageCount, paginated: paginatedRows, total, setPage, setPageSize } = usePagination(rows);

    return (
        <AppLayout>
            <Head title="Attendance Reports" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
                        <BarChart3 className="h-6 w-6 text-primary" /> Attendance Reports
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Permission-aware attendance for {meta.scope.toLowerCase()} — approved leave is counted as
                        leave, never a false absence.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button asChild variant="outline" className="gap-2">
                        <a href={exportUrls.csv}>
                            <Download className="h-4 w-4" /> CSV
                        </a>
                    </Button>
                    <Button asChild variant="outline" className="gap-2">
                        <a href={exportUrls.pdf}>
                            <FileText className="h-4 w-4" /> PDF
                        </a>
                    </Button>
                </div>
            </div>

            <Card className="mt-6">
                <CardContent className="p-5">
                    <form onSubmit={apply} className="flex flex-wrap items-end gap-4">
                        <div className="w-40">
                            <Label htmlFor="from">From</Label>
                            <Input
                                id="from"
                                type="date"
                                value={from}
                                className="mt-1"
                                onChange={(e) => setFrom(e.target.value)}
                            />
                        </div>
                        <div className="w-40">
                            <Label htmlFor="to">To</Label>
                            <Input id="to" type="date" value={to} className="mt-1" onChange={(e) => setTo(e.target.value)} />
                        </div>
                        <div className="w-56">
                            <Label htmlFor="department_id">Scope</Label>
                            <Select value={departmentId} onValueChange={setDepartmentId}>
                                <SelectTrigger id="department_id" className="mt-1">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Organization-wide</SelectItem>
                                    {departments.map((d) => (
                                        <SelectItem key={d.id} value={String(d.id)}>
                                            {d.name} department
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <Button type="submit">Apply</Button>
                        <span className="pb-2 text-sm text-muted-foreground">
                            {meta.from_label} – {meta.to_label}
                        </span>
                    </form>
                </CardContent>
            </Card>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard icon={Users} label="Employees in scope" value={meta.employees} />
                <StatCard
                    icon={CalendarClock}
                    label="Attendance rate"
                    value={`${totals.attendance_rate}%`}
                    iconClass="bg-emerald-100 text-emerald-700"
                />
                <StatCard
                    icon={UserX}
                    label="Total absences"
                    value={totals.absent}
                    iconClass="bg-rose-100 text-rose-700"
                />
                <StatCard
                    icon={Clock}
                    label="Late incidents"
                    value={totals.late}
                    iconClass="bg-amber-100 text-amber-700"
                />
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-sm">Attendance rate trend (%)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {trend.values.length > 0 ? (
                            <LineAreaChart values={trend.values} labels={trend.labels} highlightIndex={trend.highlight} />
                        ) : (
                            <p className="py-10 text-center text-sm text-muted-foreground">No data for this range.</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Status distribution</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {statusDistribution.values.length > 0 ? (
                            <BarChart
                                values={statusDistribution.values}
                                labels={statusDistribution.labels}
                                highlightIndex={statusDistribution.highlight}
                            />
                        ) : (
                            <p className="py-10 text-center text-sm text-muted-foreground">No records yet.</p>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* AI decision support */}
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <Sparkles className="h-4 w-4 text-primary" /> AI narrative
                            </CardTitle>
                            <CardDescription>
                                {decisionSupport.summary
                                    ? `Latest summary for ${decisionSupport.summary.period_label}`
                                    : 'No summary generated for this month yet.'}
                            </CardDescription>
                        </div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={route('report-summaries.index')}>Open</Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm leading-relaxed text-foreground/90">
                            {decisionSupport.summary?.narrative ??
                                'Generate a monthly AI narrative on the Report Summaries page to surface it here.'}
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <AlertTriangle className="h-4 w-4 text-rose-600" /> High-risk employees
                        </CardTitle>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={route('ai-insights.index')}>All</Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {decisionSupport.highRisk.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">No high-risk employees.</p>
                        ) : (
                            <ul className="space-y-2">
                                {decisionSupport.highRisk.map((r) => (
                                    <li key={r.employee} className="flex items-center justify-between text-sm">
                                        <span className="truncate">{r.employee}</span>
                                        <Badge variant="secondary">{Math.round(r.risk_score * 100)}%</Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-4">
                <CardHeader>
                    <CardTitle className="text-sm">Per-employee breakdown</CardTitle>
                    <CardDescription>{meta.working_days} working days in range</CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Employee</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead className="text-right">Present</TableHead>
                                <TableHead className="text-right">Late</TableHead>
                                <TableHead className="text-right">Absent</TableHead>
                                <TableHead className="text-right">Leave</TableHead>
                                <TableHead className="text-right">Hours</TableHead>
                                <TableHead className="text-right">Attend %</TableHead>
                                <TableHead className="text-right">Punct %</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {paginatedRows.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={9} className="py-8 text-center text-muted-foreground">
                                        No employees in scope.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                paginatedRows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <div className="font-medium">{row.name}</div>
                                            <div className="text-xs text-muted-foreground">{row.employee_code}</div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{row.department}</TableCell>
                                        <TableCell className="text-right">{row.present}</TableCell>
                                        <TableCell className="text-right">{row.late}</TableCell>
                                        <TableCell className="text-right">{row.absent}</TableCell>
                                        <TableCell className="text-right">{row.leave}</TableCell>
                                        <TableCell className="text-right">{row.worked_hours}</TableCell>
                                        <TableCell className="text-right font-medium">{row.attendance_rate}%</TableCell>
                                        <TableCell className="text-right">{row.punctuality_rate}%</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    <TablePagination
                        page={page}
                        pageCount={pageCount}
                        pageSize={pageSize}
                        total={total}
                        onPageChange={setPage}
                        onPageSizeChange={setPageSize}
                        className="mt-3 border-t border-border pt-3"
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}
