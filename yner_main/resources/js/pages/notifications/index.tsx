import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCheck, Clock3, ExternalLink, Mail, Megaphone, TriangleAlert } from 'lucide-react';
import { route } from 'ziggy-js';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';

interface NotificationItem {
    id: string;
    type: string;
    title: string;
    message: string;
    action_url: string | null;
    action_label: string | null;
    meta: Record<string, unknown>;
    read_at: string | null;
    created_at: string | null;
    is_unread: boolean;
}

interface NotificationPagination {
    data: NotificationItem[];
    current_page: number;
    last_page: number;
    total: number;
}

interface NotificationsProps {
    notifications: NotificationPagination;
}

function typeLabel(type: string): string {
    if (type.startsWith('permission-request.')) {
        return 'Permission';
    }

    if (type === 'attendance.alert') {
        return 'AI alert';
    }

    if (type === 'report-summary.ready') {
        return 'Report';
    }

    if (type === 'attendance.sign-in-reminder') {
        return 'Reminder';
    }

    return 'System';
}

function typeIcon(type: string) {
    if (type === 'attendance.alert') {
        return TriangleAlert;
    }

    if (type === 'report-summary.ready') {
        return Mail;
    }

    if (type === 'attendance.sign-in-reminder') {
        return Clock3;
    }

    return Megaphone;
}

export default function Notifications({ notifications }: NotificationsProps) {
    const { flash } = usePage<SharedData>().props;
    const [processing, setProcessing] = useState(false);
    const unreadCount = notifications.data.filter((item) => item.is_unread).length;

    const markRead = (url: string) => {
        router.patch(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <AppLayout>
            <Head title="Notifications" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
                        <Megaphone className="h-6 w-6 text-primary" /> Notification inbox
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Alerts from permissions, attendance scoring, report generation, and reminders live here.
                    </p>
                </div>

                {notifications.total > 0 && (
                    <div className="flex items-center gap-2">
                        <Badge variant="secondary">{unreadCount} unread</Badge>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={unreadCount === 0 || processing}
                            onClick={() => markRead(route('notifications.read-all'))}
                        >
                            <CheckCheck className="mr-2 h-4 w-4" /> Mark all read
                        </Button>
                    </div>
                )}
            </div>

            {flash.status && (
                <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {flash.status}
                </div>
            )}

            <div className="mt-6 space-y-4">
                {notifications.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            You are all caught up.
                        </CardContent>
                    </Card>
                ) : (
                    notifications.data.map((notification) => {
                        const Icon = typeIcon(notification.type);

                        return (
                            <Card key={notification.id} className={notification.is_unread ? 'border-primary/30 bg-primary/5' : ''}>
                                <CardHeader className="pb-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="flex items-start gap-3">
                                            <div className="mt-0.5 flex h-10 w-10 items-center justify-center rounded-xl bg-background text-primary shadow-sm ring-1 ring-border">
                                                <Icon className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <CardTitle className="text-base">{notification.title}</CardTitle>
                                                    {notification.is_unread && <Badge>new</Badge>}
                                                    <Badge variant="secondary">{typeLabel(notification.type)}</Badge>
                                                </div>
                                                <CardDescription className="mt-1">
                                                    {notification.created_at ? `Sent ${notification.created_at}` : 'System notification'}
                                                </CardDescription>
                                            </div>
                                        </div>

                                        {!notification.is_unread && <Badge variant="outline">read</Badge>}
                                    </div>
                                </CardHeader>

                                <CardContent className="space-y-4">
                                    <p className="text-sm leading-relaxed text-foreground/90">{notification.message}</p>

                                    <div className="flex flex-wrap items-center gap-2">
                                        {notification.action_url && (
                                            <Button asChild size="sm" className="gap-2">
                                                <Link href={notification.action_url}>
                                                    {notification.action_label ?? 'Open'}
                                                    <ExternalLink className="h-4 w-4" />
                                                </Link>
                                            </Button>
                                        )}

                                        {notification.is_unread && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                                onClick={() => markRead(route('notifications.read', notification.id))}
                                            >
                                                Mark read
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })
                )}
            </div>
        </AppLayout>
    );
}