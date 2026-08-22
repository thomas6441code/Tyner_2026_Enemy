import { Head, useForm, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { CheckCircle2, FileText, Lightbulb, Sparkles } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';

interface DepartmentOption {
    id: number;
    name: string;
}

interface Summary {
    id: number;
    period_month: string;
    period_label: string;
    scope: string;
    narrative: string;
    highlights: string[];
    recommendations: string[];
    model: string | null;
    fallback: boolean;
    generated_at: string | null;
}

interface ReportSummariesProps {
    summaries: Summary[];
    departments: DepartmentOption[];
    defaultMonth: string;
}

const ALL = 'all';

export default function ReportSummaries({ summaries, departments, defaultMonth }: ReportSummariesProps) {
    const { flash } = usePage<SharedData>().props;

    const form = useForm({
        period: defaultMonth,
        department_id: ALL,
        force: false as boolean,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            period: data.period,
            department_id: data.department_id === ALL ? null : Number(data.department_id),
            force: data.force,
        }));
        form.post(route('report-summaries.store'), { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Report Summaries" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
                        <FileText className="h-6 w-6 text-primary" /> AI Report Summaries
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Monthly attendance narratives written by Claude from aggregated statistics — no employee
                        names ever leave the system.
                    </p>
                </div>
            </div>

            {flash.status && (
                <div className="mt-4 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <CheckCircle2 className="h-4 w-4 shrink-0" />
                    {flash.status}
                </div>
            )}

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>Generate a summary</CardTitle>
                    <CardDescription>
                        Pick a month and scope. Each month/department is summarized once and cached — tick
                        “Regenerate” to force a fresh Claude call.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                        <div className="w-40">
                            <Label htmlFor="period">Month</Label>
                            <Input
                                id="period"
                                type="month"
                                value={form.data.period}
                                className="mt-1"
                                onChange={(e) => form.setData('period', e.target.value)}
                            />
                        </div>

                        <div className="w-56">
                            <Label htmlFor="department_id">Scope</Label>
                            <Select
                                value={form.data.department_id}
                                onValueChange={(value) => form.setData('department_id', value)}
                            >
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

                        <label className="flex items-center gap-2 pb-2 text-sm text-muted-foreground">
                            <input
                                type="checkbox"
                                checked={form.data.force}
                                onChange={(e) => form.setData('force', e.target.checked)}
                                className="h-4 w-4 rounded border-input"
                            />
                            Regenerate if cached
                        </label>

                        <Button type="submit" disabled={form.processing} className="gap-2">
                            <Sparkles className="h-4 w-4" />
                            {form.processing ? 'Generating…' : 'Generate summary'}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <div className="mt-6 space-y-4">
                {summaries.length === 0 ? (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            No summaries yet — generate one above.
                        </CardContent>
                    </Card>
                ) : (
                    summaries.map((s) => (
                        <Card key={s.id}>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <CardTitle className="text-lg">
                                        {s.period_label} · {s.scope}
                                    </CardTitle>
                                    <div className="flex items-center gap-2">
                                        {s.fallback ? (
                                            <Badge variant="secondary">offline template</Badge>
                                        ) : (
                                            <Badge variant="outline">{s.model ?? 'claude'}</Badge>
                                        )}
                                    </div>
                                </div>
                                {s.generated_at && (
                                    <CardDescription>Generated {s.generated_at}</CardDescription>
                                )}
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm leading-relaxed text-foreground/90">{s.narrative}</p>

                                {s.highlights.length > 0 && (
                                    <div>
                                        <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            <Sparkles className="h-3.5 w-3.5" /> Highlights
                                        </div>
                                        <ul className="list-inside list-disc space-y-1 text-sm text-foreground/80">
                                            {s.highlights.map((h, i) => (
                                                <li key={i}>{h}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {s.recommendations.length > 0 && (
                                    <div>
                                        <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            <Lightbulb className="h-3.5 w-3.5" /> Recommendations
                                        </div>
                                        <ul className="list-inside list-disc space-y-1 text-sm text-foreground/80">
                                            {s.recommendations.map((r, i) => (
                                                <li key={i}>{r}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>
        </AppLayout>
    );
}
