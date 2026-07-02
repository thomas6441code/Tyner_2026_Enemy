import { Head } from '@inertiajs/react';
import { AlertTriangle, Brain, Gauge, TrendingUp } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface Anomaly {
    id: number;
    employee: string;
    work_date: string;
    method: string;
    score: number;
    explanation: string;
}

interface TopFactor {
    feature: string;
    value: number;
    importance: number;
}

interface Prediction {
    id: number;
    employee: string;
    risk_score: number;
    risk_level: 'low' | 'medium' | 'high';
    top_factors: TopFactor[];
    computed_at: string | null;
}

interface FeatureImportance {
    feature: string;
    importance: number;
}

interface AiInsightsProps {
    anomalies: Anomaly[];
    predictions: Prediction[];
    featureImportances: FeatureImportance[];
    modelVersion: string | null;
}

const RISK_VARIANT: Record<Prediction['risk_level'], 'success' | 'secondary' | 'destructive'> = {
    low: 'success',
    medium: 'secondary',
    high: 'destructive',
};

const METHOD_LABEL: Record<string, string> = {
    isolation_forest: 'Isolation Forest',
    zscore: 'Z-score',
};

function humanFeature(feature: string): string {
    return feature.replace(/_/g, ' ');
}

export default function AiInsights({
    anomalies,
    predictions,
    featureImportances,
    modelVersion,
}: AiInsightsProps) {
    const highRisk = predictions.filter((p) => p.risk_level === 'high').length;

    return (
        <AppLayout>
            <Head title="AI Insights" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
                        <Brain className="h-6 w-6 text-primary" /> AI Insights
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Self-hosted anomaly detection and absenteeism-risk scoring over attendance history.
                    </p>
                </div>
                {modelVersion && (
                    <Badge variant="outline" className="mt-1">
                        model {modelVersion}
                    </Badge>
                )}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardContent className="flex items-center gap-3 p-5">
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                            <AlertTriangle className="h-5 w-5" />
                        </span>
                        <div>
                            <div className="text-2xl font-bold tracking-tight">{anomalies.length}</div>
                            <div className="text-xs text-muted-foreground">Anomalies flagged</div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center gap-3 p-5">
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-red-50 text-red-600">
                            <Gauge className="h-5 w-5" />
                        </span>
                        <div>
                            <div className="text-2xl font-bold tracking-tight">{highRisk}</div>
                            <div className="text-xs text-muted-foreground">High-risk employees</div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center gap-3 p-5">
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                            <TrendingUp className="h-5 w-5" />
                        </span>
                        <div>
                            <div className="text-2xl font-bold tracking-tight">{predictions.length}</div>
                            <div className="text-xs text-muted-foreground">Employees scored</div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Absenteeism risk</CardTitle>
                        <CardDescription>Predicted risk per employee, highest first.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {predictions.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No risk scores yet — run <code>ai:score-attendance</code>.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Risk</TableHead>
                                        <TableHead>Score</TableHead>
                                        <TableHead>Top factors</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {predictions.map((p) => (
                                        <TableRow key={p.id}>
                                            <TableCell className="font-medium">{p.employee}</TableCell>
                                            <TableCell>
                                                <Badge variant={RISK_VARIANT[p.risk_level]}>{p.risk_level}</Badge>
                                            </TableCell>
                                            <TableCell>{(p.risk_score * 100).toFixed(0)}%</TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {p.top_factors.map((f) => humanFeature(f.feature)).join(', ') || '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Feature importance</CardTitle>
                        <CardDescription>What the model relied on.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {featureImportances.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">No model run yet.</p>
                        ) : (
                            featureImportances.map((f) => (
                                <div key={f.feature}>
                                    <div className="mb-1 flex justify-between text-xs">
                                        <span className="capitalize text-foreground/80">{humanFeature(f.feature)}</span>
                                        <span className="text-muted-foreground">{(f.importance * 100).toFixed(0)}%</span>
                                    </div>
                                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-primary"
                                            style={{ width: `${Math.min(100, f.importance * 100)}%` }}
                                        />
                                    </div>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>Detected anomalies</CardTitle>
                    <CardDescription>
                        Unusual attendance days flagged by Isolation Forest and per-employee z-score.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {anomalies.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">No anomalies detected.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Method</TableHead>
                                    <TableHead>Score</TableHead>
                                    <TableHead>Explanation</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {anomalies.map((a) => (
                                    <TableRow key={a.id}>
                                        <TableCell className="font-medium">{a.employee}</TableCell>
                                        <TableCell>{a.work_date}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">{METHOD_LABEL[a.method] ?? a.method}</Badge>
                                        </TableCell>
                                        <TableCell>{a.score.toFixed(2)}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{a.explanation}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </AppLayout>
    );
}
