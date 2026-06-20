import { Head, router } from '@inertiajs/react';
import { Building2, Inbox, Pencil, Plus, Sigma, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { DepartmentFormDialog } from '@/components/department-form-dialog';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface Department {
    id: number;
    name: string;
    description: string | null;
    employees_count: number;
}

interface DepartmentsIndexProps {
    departments: {
        data: Department[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    stats: { departments: number; employees: number; avgPerDepartment: number; empty: number };
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function DepartmentsIndex({ departments, stats, actions, status }: DepartmentsIndexProps) {
    const [dialog, setDialog] = useState<{ record: Department | null } | null>(null);
    const [deleting, setDeleting] = useState<Department | null>(null);

    return (
        <AppLayout>
            <Head title="Departments" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Departments</h1>
                    <p className="text-sm text-muted-foreground">Organise employees into departments</p>
                </div>
                {actions.create && (
                    <Button onClick={() => setDialog({ record: null })}>
                        <Plus className="h-4 w-4" /> New Department
                    </Button>
                )}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard icon={Building2} iconClass="bg-blue-50 text-blue-600" label="Departments" value={stats.departments} />
                <StatCard icon={Users} iconClass="bg-emerald-50 text-emerald-600" label="Employees Assigned" value={stats.employees} />
                <StatCard icon={Sigma} iconClass="bg-violet-50 text-violet-600" label="Avg per Department" value={stats.avgPerDepartment} />
                <StatCard icon={Inbox} iconClass="bg-amber-50 text-amber-600" label="Empty Departments" value={stats.empty} />
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
                                <TableHead>Description</TableHead>
                                <TableHead>Employees</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {departments.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={4} className="py-10 text-center text-muted-foreground">
                                        No departments yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                departments.data.map((department) => (
                                    <TableRow key={department.id}>
                                        <TableCell className="font-medium">{department.name}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {department.description}
                                        </TableCell>
                                        <TableCell>{department.employees_count}</TableCell>
                                        <TableCell className="text-right">
                                            {actions.update && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mr-1"
                                                    onClick={() => setDialog({ record: department })}
                                                >
                                                    <Pencil className="h-4 w-4" /> Edit
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setDeleting(department)}
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
                <Pagination links={departments.links} />
            </div>

            {dialog && <DepartmentFormDialog record={dialog.record} onClose={() => setDialog(null)} />}

            {deleting && (
                <ConfirmDialog
                    title="Delete department"
                    description={`Delete "${deleting.name}"? This action cannot be undone.`}
                    onCancel={() => setDeleting(null)}
                    onConfirm={() =>
                        router.delete(route('departments.destroy', deleting.id), {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
