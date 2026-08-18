import { Head } from '@inertiajs/react';
import { CheckCircle2, Clock, UserPlus, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

import { InvitationLinkDialog } from '@/components/invitation-link-dialog';
import { Pagination } from '@/components/pagination';
import { RegistrationApproveDialog, RegistrationRejectDialog } from '@/components/registration-review-dialog';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface RegistrationRow {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    department: string | null;
    note: string | null;
    status: string;
    status_label: string;
    reviewer: string | null;
    review_note: string | null;
    employee_code: string | null;
    submitted_at: string | null;
    can_review: boolean;
}

interface Option {
    id: number;
    name: string;
}

interface Props {
    requests: {
        data: RegistrationRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    stats: { pending: number; approved: number; rejected: number };
    formData: { departments: Option[]; workSchedules: Option[]; workLocations: Option[] };
    status?: string;
    invitationUrl?: string;
}

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'success'> = {
    pending: 'secondary',
    approved: 'success',
    rejected: 'destructive',
};

export default function RegistrationRequestsIndex({ requests, stats, formData, status, invitationUrl }: Props) {
    const [approving, setApproving] = useState<RegistrationRow | null>(null);
    const [rejecting, setRejecting] = useState<RegistrationRow | null>(null);
    const [linkUrl, setLinkUrl] = useState<string | null>(null);

    // The activation URL arrives as a one-shot flash prop; surface it the moment it lands.
    useEffect(() => {
        if (invitationUrl) {
            setLinkUrl(invitationUrl);
        }
    }, [invitationUrl]);

    return (
        <AppLayout>
            <Head title="Registration Requests" />

            <div>
                <h1 className="text-2xl font-bold tracking-tight">Registration Requests</h1>
                <p className="text-sm text-muted-foreground">
                    Review who may join EAPMS. Approving creates an employee record and issues a single-use activation
                    link — no account exists until that link is redeemed.
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
                                    <TableHead>Applicant</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>Submitted</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {requests.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                                            <UserPlus className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                            No registration requests yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    requests.data.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <div className="font-medium">{row.name}</div>
                                                <div className="text-xs text-muted-foreground">{row.email}</div>
                                                {row.phone && (
                                                    <div className="text-xs text-muted-foreground">{row.phone}</div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.department ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.submitted_at ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={STATUS_VARIANT[row.status] ?? 'secondary'}>
                                                    {row.status_label}
                                                </Badge>
                                                {row.employee_code && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {row.employee_code}
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
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="mr-1"
                                                            onClick={() => setApproving(row)}
                                                        >
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-destructive hover:text-destructive"
                                                            onClick={() => setRejecting(row)}
                                                        >
                                                            Reject
                                                        </Button>
                                                    </>
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

            {approving && (
                <RegistrationApproveDialog
                    record={approving}
                    departments={formData.departments}
                    workSchedules={formData.workSchedules}
                    workLocations={formData.workLocations}
                    onClose={() => setApproving(null)}
                />
            )}

            {rejecting && <RegistrationRejectDialog record={rejecting} onClose={() => setRejecting(null)} />}

            {linkUrl && <InvitationLinkDialog url={linkUrl} onClose={() => setLinkUrl(null)} />}
        </AppLayout>
    );
}
