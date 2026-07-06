import { type LucideIcon, MoreHorizontal } from 'lucide-react';
import { ReactNode } from 'react';

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type Tone = 'emerald' | 'rose' | 'amber' | 'sky' | 'indigo' | 'slate' | 'violet';

const ICON_TONES: Record<Tone, string> = {
    emerald: 'bg-emerald-50 text-emerald-600',
    rose: 'bg-rose-50 text-rose-600',
    amber: 'bg-amber-50 text-amber-600',
    sky: 'bg-sky-50 text-sky-600',
    indigo: 'bg-indigo-50 text-indigo-600',
    slate: 'bg-slate-100 text-slate-600',
    violet: 'bg-violet-50 text-violet-600',
};

const VALUE_TONES: Record<Tone, string> = {
    emerald: 'text-emerald-600',
    rose: 'text-rose-600',
    amber: 'text-amber-600',
    sky: 'text-sky-600',
    indigo: 'text-indigo-600',
    slate: 'text-slate-700',
    violet: 'text-violet-600',
};

export function StatTile({
    icon: Icon,
    label,
    value,
    tone = 'slate',
}: {
    icon: LucideIcon;
    label: string;
    value: ReactNode;
    tone?: Tone;
}) {
    return (
        <div className="rounded-xl border bg-card p-4 text-center shadow-sm">
            <span className={cn('mx-auto flex h-10 w-10 items-center justify-center rounded-lg', ICON_TONES[tone])}>
                <Icon className="h-5 w-5" />
            </span>
            <div className="mt-3 text-xs font-medium text-muted-foreground">{label}</div>
            <div className={cn('mt-1 text-xl font-bold leading-tight', VALUE_TONES[tone])}>{value}</div>
        </div>
    );
}

export function ChartCard({
    title,
    action,
    className,
    children,
}: {
    title: string;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card className={className}>
            <CardContent className="p-5">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {action ?? (
                        <button type="button" className="text-muted-foreground hover:text-foreground" aria-label="Options">
                            <MoreHorizontal className="h-4 w-4" />
                        </button>
                    )}
                </div>
                {children}
            </CardContent>
        </Card>
    );
}

export function DetailTile({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="rounded-xl border bg-card p-4 shadow-sm">
            <div className="text-xs font-medium text-muted-foreground">{label}</div>
            <div className="mt-1 truncate text-sm font-semibold text-foreground">{value}</div>
        </div>
    );
}

export interface DonutSegment {
    label: string;
    value: number;
    color: string;
}

export function Donut({ segments, total, caption }: { segments: DonutSegment[]; total: number; caption: string }) {
    const size = 180;
    const stroke = 18;
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    const sum = segments.reduce((acc, s) => acc + s.value, 0) || 1;

    let offset = 0;

    return (
        <div className="flex flex-col items-center">
            <div className="relative" style={{ width: size, height: size }}>
                <svg width={size} height={size} className="-rotate-90" role="img" aria-label={caption}>
                    <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth={stroke} className="stroke-muted" />
                    {segments.map((s, i) => {
                        const len = (s.value / sum) * c;
                        const el = (
                            <circle
                                key={i}
                                cx={size / 2}
                                cy={size / 2}
                                r={r}
                                fill="none"
                                strokeWidth={stroke}
                                stroke={s.color}
                                strokeDasharray={`${len} ${c - len}`}
                                strokeDashoffset={-offset}
                            />
                        );
                        offset += len;
                        return el;
                    })}
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-3xl font-bold tracking-tight">{total}</span>
                    <span className="text-xs text-muted-foreground">{caption}</span>
                </div>
            </div>
            <div className="mt-4 grid w-full grid-cols-2 gap-2 text-xs">
                {segments.map((s) => (
                    <span key={s.label} className="flex items-center gap-2">
                        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: s.color }} />
                        <span className="truncate text-muted-foreground">{s.label}</span>
                        <span className="ml-auto font-semibold text-foreground">{s.value}</span>
                    </span>
                ))}
            </div>
        </div>
    );
}
