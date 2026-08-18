import { Head, router } from '@inertiajs/react';
import { CircleDot, MapPin, MapPinOff, Pencil, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { WorkLocationFormDialog, type WorkLocationRecord } from '@/components/work-location-form-dialog';
import AppLayout from '@/layouts/app-layout';

interface WorkLocation extends WorkLocationRecord {
    employees_count: number;
    departments_count: number;
}

interface WorkLocationsIndexProps {
    workLocations: {
        data: WorkLocation[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    stats: { locations: number; active: number; employees: number; unassigned: number };
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function WorkLocationsIndex({ workLocations, stats, actions, status }: WorkLocationsIndexProps) {
    const [dialog, setDialog] = useState<{ record: WorkLocation | null } | null>(null);
    const [deleting, setDeleting] = useState<WorkLocation | null>(null);

    return (
        <AppLayout>
            <Head title="Work Locations" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Work Locations</h1>
                    <p className="text-sm text-muted-foreground">
                        Geofenced sites employees may check in from on a phone
                    </p>
                </div>
                {actions.create && (
                    <Button onClick={() => setDialog({ record: null })}>
                        <Plus className="h-4 w-4" /> New Work Location
                    </Button>
                )}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard icon={MapPin} iconClass="bg-blue-50 text-blue-600" label="Locations" value={stats.locations} />
                <StatCard icon={CircleDot} iconClass="bg-emerald-50 text-emerald-600" label="Active" value={stats.active} />
                <StatCard icon={Users} iconClass="bg-violet-50 text-violet-600" label="Employees Assigned" value={stats.employees} />
                <StatCard icon={MapPinOff} iconClass="bg-amber-50 text-amber-600" label="Unused Locations" value={stats.unassigned} />
            </div>

            {status && (
                <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700">
                    {status}
                </div>
            )}

            <Card className="mt-6">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Address</TableHead>
                                <TableHead>Coordinates</TableHead>
                                <TableHead>Radius</TableHead>
                                <TableHead>Assigned</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {workLocations.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="py-10 text-center text-muted-foreground">
                                        No work locations yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                workLocations.data.map((workLocation) => (
                                    <TableRow key={workLocation.id}>
                                        <TableCell className="font-medium">{workLocation.name}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {workLocation.address ?? '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {workLocation.latitude.toFixed(5)}, {workLocation.longitude.toFixed(5)}
                                        </TableCell>
                                        <TableCell>{workLocation.radius_meters} m</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {workLocation.employees_count} emp · {workLocation.departments_count} dept
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={
                                                    workLocation.is_active
                                                        ? 'rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700'
                                                        : 'rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground'
                                                }
                                            >
                                                {workLocation.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {actions.update && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    onClick={() => setDialog({ record: workLocation })}
                                                >
                                                    <Pencil className="h-4 w-4" /> Edit
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setDeleting(workLocation)}
                                                >
                                                    <Trash2 className="h-4 w-4" /> Delete
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
                <Pagination links={workLocations.links} />
            </div>

            {dialog && <WorkLocationFormDialog record={dialog.record} onClose={() => setDialog(null)} />}

            {deleting && (
                <ConfirmDialog
                    title="Delete work location"
                    description={`Delete "${deleting.name}"? Employees and departments assigned to it will lose their geofence and will no longer be able to check in from a phone.`}
                    onCancel={() => setDeleting(null)}
                    onConfirm={() =>
                        router.delete(route('work-locations.destroy', deleting.id), {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
