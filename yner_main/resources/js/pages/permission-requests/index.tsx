import { Head, router } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, Clock, Eye, Gavel, Paperclip, Pencil, Plus, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { PermissionRequestFormDialog, type PermissionRecord } from '@/components/permission-request-form-dialog';
import { PermissionReviewDialog } from '@/components/permission-review-dialog';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Badge, type BadgeProps } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

interface PermissionRow {
    id: number;
    employee: string | null;
    type: string;
    type_label: string;
    start_date: string;
    end_date: string;
    reason: string;
    status: string;
    status_label: string;
    reviewer: string | null;
    review_note: string | null;
    reviewed_at: string | null;
    has_attachment: boolean;
    can_review: boolean;
    can_edit: boolean;
    can_cancel: boolean;
}

interface Option {
    value: string;
    label: string;
}

interface PermissionsIndexProps {
    requests: {
        data: PermissionRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    stats: { pending: number; approved: number; rejected: number };
    types: Option[];
    statuses: Option[];
    actions: { create: boolean };
    status?: string;
}

const statusVariant: Record<string, BadgeProps['variant']> = {
    pending: 'outline',
    approved: 'success',
    rejected: 'destructive',
    cancelled: 'secondary',
};

export default function PermissionsIndex({ requests, stats, types, actions, status }: PermissionsIndexProps) {
    const [dialog, setDialog] = useState<{ record: PermissionRecord | null } | null>(null);
    const [reviewing, setReviewing] = useState<PermissionRow | null>(null);
    const [viewing, setViewing] = useState<PermissionRow | null>(null);
    const [cancelling, setCancelling] = useState<PermissionRow | null>(null);
    const [filter, setFilter] = useState<string>('all');

    const rows = useMemo(
        () => (filter === 'all' ? requests.data : requests.data.filter((r) => r.status === filter)),
        [requests.data, filter],
    );

    const filters = [{ value: 'all', label: 'All' }, { value: 'pending', label: 'Pending' }, { value: 'approved', label: 'Approved' }, { value: 'rejected', label: 'Rejected' }];

    return (
        <AppLayout>
            <Head title="Permissions" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Permissions & Leave</h1>
                    <p className="text-sm text-muted-foreground">Request and approve time off</p>
                </div>
                {actions.create && (
                    <Button onClick={() => setDialog({ record: null })}>
                        <Plus className="h-4 w-4" /> New Request
                    </Button>
                )}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-3">
                <StatCard icon={Clock} iconClass="bg-amber-50 text-amber-600" label="Pending" value={stats.pending} />
                <StatCard icon={CheckCircle2} iconClass="bg-emerald-50 text-emerald-600" label="Approved" value={stats.approved} />
                <StatCard icon={XCircle} iconClass="bg-rose-50 text-rose-600" label="Rejected" value={stats.rejected} />
            </div>

            {status && (
                <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700">
                    {status}
                </div>
            )}

            <div className="mt-6 flex flex-wrap gap-2">
                {filters.map((f) => (
                    <button
                        key={f.value}
                        type="button"
                        onClick={() => setFilter(f.value)}
                        className={cn(
                            'rounded-full border px-3 py-1 text-sm font-medium transition-colors',
                            filter === f.value
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border text-muted-foreground hover:bg-accent',
                        )}
                    >
                        {f.label}
                    </button>
                ))}
            </div>

            <Card className="mt-4">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Employee</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Dates</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Reviewer</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                                        No permission requests.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-medium">{row.employee ?? '—'}</TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-1.5">
                                                {row.type_label}
                                                {row.has_attachment && (
                                                    <Paperclip className="h-3.5 w-3.5 text-muted-foreground" />
                                                )}
                                            </span>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {row.start_date === row.end_date
                                                ? row.start_date
                                                : `${row.start_date} → ${row.end_date}`}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={statusVariant[row.status] ?? 'secondary'}>
                                                {row.status_label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{row.reviewer ?? '—'}</TableCell>
                                        <TableCell className="whitespace-nowrap text-right">
                                            <Button variant="ghost" size="sm" className="mr-1" onClick={() => setViewing(row)}>
                                                <Eye className="h-4 w-4" /> View
                                            </Button>
                                            {row.can_review && (
                                                <Button variant="ghost" size="sm" className="mr-1" onClick={() => setReviewing(row)}>
                                                    <Gavel className="h-4 w-4" /> Review
                                                </Button>
                                            )}
                                            {row.can_edit && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    onClick={() =>
                                                        setDialog({
                                                            record: {
                                                                id: row.id,
                                                                type: row.type,
                                                                start_date: row.start_date,
                                                                end_date: row.end_date,
                                                                reason: row.reason,
                                                                has_attachment: row.has_attachment,
                                                            },
                                                        })
                                                    }
                                                >
                                                    <Pencil className="h-4 w-4" /> Edit
                                                </Button>
                                            )}
                                            {row.can_cancel && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setCancelling(row)}
                                                >
                                                    <XCircle className="h-4 w-4" /> Cancel
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <div className="mt-3">
                <Pagination links={requests.links} />
            </div>

            {dialog && (
                <PermissionRequestFormDialog
                    record={dialog.record}
                    types={types}
                    onClose={() => setDialog(null)}
                />
            )}

            {reviewing && (
                <PermissionReviewDialog
                    record={reviewing}
                    onClose={() => setReviewing(null)}
                />
            )}

            {viewing && (
                <Dialog open onOpenChange={(open) => !open && setViewing(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <CalendarClock className="h-5 w-5 text-primary" /> {viewing.type_label}
                            </DialogTitle>
                        </DialogHeader>
                        <dl className="grid grid-cols-3 gap-y-3 text-sm">
                            <dt className="text-muted-foreground">Employee</dt>
                            <dd className="col-span-2">{viewing.employee ?? '—'}</dd>
                            <dt className="text-muted-foreground">Dates</dt>
                            <dd className="col-span-2">
                                {viewing.start_date} → {viewing.end_date}
                            </dd>
                            <dt className="text-muted-foreground">Reason</dt>
                            <dd className="col-span-2">{viewing.reason}</dd>
                            <dt className="text-muted-foreground">Status</dt>
                            <dd className="col-span-2">
                                <Badge variant={statusVariant[viewing.status] ?? 'secondary'}>
                                    {viewing.status_label}
                                </Badge>
                            </dd>
                            <dt className="text-muted-foreground">Reviewer</dt>
                            <dd className="col-span-2">{viewing.reviewer ?? '—'}</dd>
                            {viewing.review_note && (
                                <>
                                    <dt className="text-muted-foreground">Review Note</dt>
                                    <dd className="col-span-2">{viewing.review_note}</dd>
                                </>
                            )}
                            {viewing.has_attachment && (
                                <>
                                    <dt className="text-muted-foreground">Attachment</dt>
                                    <dd className="col-span-2">
                                        <a
                                            href={route('permission-requests.attachment', viewing.id)}
                                            className="inline-flex items-center gap-1 text-primary underline"
                                        >
                                            <Paperclip className="h-3.5 w-3.5" /> Download
                                        </a>
                                    </dd>
                                </>
                            )}
                        </dl>
                    </DialogContent>
                </Dialog>
            )}

            {cancelling && (
                <ConfirmDialog
                    title="Cancel request"
                    description={`Cancel this ${cancelling.type_label} request? This keeps a record but withdraws it.`}
                    confirmLabel="Cancel Request"
                    onCancel={() => setCancelling(null)}
                    onConfirm={() =>
                        router.delete(route('permission-requests.destroy', cancelling.id), {
                            preserveScroll: true,
                            onSuccess: () => setCancelling(null),
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
