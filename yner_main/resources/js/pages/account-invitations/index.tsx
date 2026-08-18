import { Head, router } from '@inertiajs/react';
import { Ban, CheckCircle2, Clock, MailCheck, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { InvitationLinkDialog } from '@/components/invitation-link-dialog';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface InvitationRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    email: string;
    status: string;
    status_label: string;
    expires_at: string;
    used_at: string | null;
    created_by: string | null;
    created_at: string | null;
    can_resend: boolean;
    can_revoke: boolean;
}

interface Props {
    invitations: {
        data: InvitationRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    stats: { pending: number; used: number; expired: number; revoked: number };
    status?: string;
    invitationUrl?: string;
}

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'success' | 'outline'> = {
    pending: 'secondary',
    used: 'success',
    expired: 'outline',
    revoked: 'destructive',
};

export default function AccountInvitationsIndex({ invitations, stats, status, invitationUrl }: Props) {
    const [revoking, setRevoking] = useState<InvitationRow | null>(null);
    const [linkUrl, setLinkUrl] = useState<string | null>(null);

    useEffect(() => {
        if (invitationUrl) {
            setLinkUrl(invitationUrl);
        }
    }, [invitationUrl]);

    return (
        <AppLayout>
            <Head title="Invitations" />

            <div>
                <h1 className="text-2xl font-bold tracking-tight">Invitations</h1>
                <p className="text-sm text-muted-foreground">
                    Activation links issued to approved applicants. Each one works exactly once. Resend revokes the old
                    link and issues a replacement.
                </p>
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-4">
                <StatCard icon={Clock} iconClass="bg-amber-50 text-amber-600" label="Pending" value={stats.pending} />
                <StatCard
                    icon={CheckCircle2}
                    iconClass="bg-emerald-50 text-emerald-600"
                    label="Used"
                    value={stats.used}
                />
                <StatCard icon={Ban} iconClass="bg-muted text-muted-foreground" label="Expired" value={stats.expired} />
                <StatCard icon={Ban} iconClass="bg-rose-50 text-rose-600" label="Revoked" value={stats.revoked} />
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
                                    <TableHead>Email</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invitations.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                                            <MailCheck className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                            No invitations issued yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    invitations.data.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <div className="font-medium">{row.employee ?? '—'}</div>
                                                {row.employee_code && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {row.employee_code}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">{row.email}</TableCell>
                                            <TableCell className="text-muted-foreground">{row.expires_at}</TableCell>
                                            <TableCell>
                                                <Badge variant={STATUS_VARIANT[row.status] ?? 'secondary'}>
                                                    {row.status_label}
                                                </Badge>
                                                {row.used_at && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {row.used_at}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {row.can_resend && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="mr-1"
                                                        onClick={() =>
                                                            router.post(
                                                                route('account-invitations.resend', row.id),
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        <RefreshCw className="h-4 w-4" /> Resend
                                                    </Button>
                                                )}
                                                {row.can_revoke && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive hover:text-destructive"
                                                        onClick={() => setRevoking(row)}
                                                    >
                                                        <Ban className="h-4 w-4" /> Revoke
                                                    </Button>
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
                <Pagination links={invitations.links} />
            </div>

            {revoking && (
                <ConfirmDialog
                    title="Revoke invitation"
                    description={`Revoke the activation link for ${revoking.email}? The link stops working immediately. You can issue a new one with Resend.`}
                    confirmLabel="Revoke"
                    onCancel={() => setRevoking(null)}
                    onConfirm={() =>
                        router.delete(route('account-invitations.destroy', revoking.id), {
                            preserveScroll: true,
                            onSuccess: () => setRevoking(null),
                        })
                    }
                />
            )}

            {linkUrl && <InvitationLinkDialog url={linkUrl} onClose={() => setLinkUrl(null)} />}
        </AppLayout>
    );
}
