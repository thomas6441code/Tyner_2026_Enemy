import { type LucideIcon } from 'lucide-react';

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface StatCardProps {
    icon: LucideIcon;
    label: string;
    value: number | string;
    iconClass?: string;
}

/** Compact KPI card for the top of list pages (Employees, Departments, Work Schedules). */
export function StatCard({ icon: Icon, label, value, iconClass = 'bg-primary/10 text-primary' }: StatCardProps) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-5">
                <span className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-xl', iconClass)}>
                    <Icon className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                    <div className="text-2xl font-bold leading-tight tracking-tight">{value}</div>
                    <div className="truncate text-sm text-muted-foreground">{label}</div>
                </div>
            </CardContent>
        </Card>
    );
}
