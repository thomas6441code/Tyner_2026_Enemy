import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';

import { Pagination } from '@/components/pagination';
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
    actions: { create: boolean; update: boolean; delete: boolean };
    status?: string;
}

export default function DepartmentsIndex({ departments, actions, status }: DepartmentsIndexProps) {
    const destroy = (department: Department) => {
        if (confirm('Delete this department?')) {
            router.delete(route('departments.destroy', department.id));
        }
    };

    return (
        <AppLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Departments</h2>
                    {actions.create && (
                        <Button asChild size="sm">
                            <Link href={route('departments.create')}>New Department</Link>
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Departments" />

            {status && <div className="mb-4 text-sm font-medium text-emerald-600">{status}</div>}

            <Card>
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
                                    <TableCell colSpan={4} className="py-8 text-center text-muted-foreground">
                                        No departments yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                departments.data.map((department) => (
                                    <TableRow key={department.id}>
                                        <TableCell>{department.name}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {department.description}
                                        </TableCell>
                                        <TableCell>{department.employees_count}</TableCell>
                                        <TableCell className="text-right">
                                            {actions.update && (
                                                <Button asChild variant="outline" size="sm" className="mr-2">
                                                    <Link href={route('departments.edit', department.id)}>Edit</Link>
                                                </Button>
                                            )}
                                            {actions.delete && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => destroy(department)}
                                                >
                                                    Delete
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
        </AppLayout>
    );
}
