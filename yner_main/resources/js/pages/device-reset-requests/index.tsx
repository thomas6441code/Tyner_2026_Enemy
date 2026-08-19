import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock, Smartphone, XCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';

interface ResetRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    current_device: string | null;
    reason: string;
    status: string;
    status_label: string;
    reviewer: string | null;
    review_note: string | null;
    reviewed_at: string | null;
    approved_until: string | null;
    used_at: string | null;
    is_usable: boolean;
    submitted_at: string | null;
    can_review: boolean;
}

interface Props {
    requests: {
        data: ResetRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { status?: string | null; employee?: string | null };
    stats: { pending: number; approved: number; rejected: number };
    status?: string;
}

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'success'> = {
    pending: 'secondary',
    approved: 'success',
    rejected: 'destructive',
};

export default function DeviceResetRequestsIndex({ requests, stats, status }: Props) {
    const [reviewing, setReviewing] = useState<{ row: ResetRow; decision: 'approve' | 'reject' } | null>(null);

    return (
        <AppLayout>
            <Head title="Device Resets" />

            <div>
                <h1 className="text-2xl font-bold tracking-tight">Device Resets</h1>
                <p className="text-sm text-muted-foreground">
                    Each account is linked to exactly one phone, and employees cannot unlink their own.
                    Approving here unlinks their current device and opens a short window for them to link a
                    new one.
                </p>
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-3">
                <StatCard icon={Clock} iconClass="bg-amber-50 text-amber-600" label="Pending" value={stats.pending} />
                <StatCard
                    icon={CheckCircle2}
                    iconClass="bg-emerald-50 text-emerald-600"
                    label="Approved"
                    value={stats.approved}
                />
                <StatCard icon={XCircle} iconClass="bg-rose-50 text-rose-600" label="Rejected" value={stats.rejected} />
            </div>

            {status && (
                <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700">
                    {status}
                </div>
            )}

            <Card className="mt-6">
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Current device</TableHead>
                                    <TableHead>Reason</TableHead>
                                    <TableHead>Submitted</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {requests.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                                            <Smartphone className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                            No device reset requests yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    requests.data.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <div className="font-medium">{row.employee ?? '—'}</div>
                                                {row.employee_code && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {row.employee_code}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.current_device ?? 'None'}
                                            </TableCell>
                                            <TableCell className="max-w-xs text-sm text-muted-foreground">
                                                {row.reason}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.submitted_at ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={STATUS_VARIANT[row.status] ?? 'default'}>
                                                    {row.status_label}
                                                </Badge>
                                                {/*
                                                  "Approved" and "still usable" are different
                                                  facts: an approval expires and is spent by the
                                                  first registration. A reviewer looking for why
                                                  someone still cannot link needs to see which.
                                                */}
                                                {row.status === 'approved' && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {row.used_at
                                                            ? `Used ${row.used_at}`
                                                            : row.is_usable
                                                              ? `Open until ${row.approved_until}`
                                                              : `Expired ${row.approved_until}`}
                                                    </div>
                                                )}
                                                {row.review_note && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {row.review_note}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {row.can_review ? (
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            size="sm"
                                                            onClick={() => setReviewing({ row, decision: 'approve' })}
                                                        >
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => setReviewing({ row, decision: 'reject' })}
                                                        >
                                                            Reject
                                                        </Button>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        {row.reviewer ? `by ${row.reviewer}` : '—'}
                                                    </span>
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
                <Pagination links={requests.links} />
            </div>

            {reviewing && (
                <ReviewDialog
                    row={reviewing.row}
                    decision={reviewing.decision}
                    onClose={() => setReviewing(null)}
                />
            )}
        </AppLayout>
    );
}

function ReviewDialog({
    row,
    decision,
    onClose,
}: {
    row: ResetRow;
    decision: 'approve' | 'reject';
    onClose: () => void;
}) {
    const { data, setData, put, processing, errors } = useForm({ review_note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        put(route(`device-reset-requests.${decision}`, row.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const approving = decision === 'approve';

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            {approving ? 'Approve' : 'Reject'} device reset for {row.employee ?? 'this employee'}
                        </DialogTitle>
                        <DialogDescription>
                            {approving
                                ? `"${row.current_device ?? 'Their current device'}" will be unlinked immediately and they will be unable to check in until they link a new phone. Confirm you have verified this request with them directly.`
                                : 'Their current device stays linked and usable. Explain why, so they know what to do next.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-4 rounded-lg border border-border bg-muted/40 p-3 text-sm">
                        <div className="font-medium">Their reason</div>
                        <p className="mt-1 text-muted-foreground">{row.reason}</p>
                    </div>

                    <div className="mt-4">
                        <Label htmlFor="review_note">
                            Note {approving ? '(optional)' : '(required)'}
                        </Label>
                        <Textarea
                            id="review_note"
                            value={data.review_note}
                            className="mt-1"
                            rows={3}
                            maxLength={1000}
                            onChange={(e) => setData('review_note', e.target.value)}
                        />
                        <InputError message={errors.review_note} className="mt-2" />
                    </div>

                    <DialogFooter className="mt-6 gap-2">
                        <Button type="button" variant="outline" onClick={onClose} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" variant={approving ? 'default' : 'destructive'} disabled={processing}>
                            {processing ? 'Saving…' : approving ? 'Approve reset' : 'Reject request'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
