import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { DepartmentForm } from '@/components/department-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

interface Department {
    id: number;
    name: string;
    description: string | null;
}

export default function EditDepartment({ department }: { department: Department }) {
    const { data, setData, put, processing, errors } = useForm({
        name: department.name,
        description: department.description ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('departments.update', department.id));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">Edit Department</h2>}>
            <Head title="Edit Department" />

            <Card className="max-w-lg">
                <CardContent className="p-6">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DepartmentForm data={data} setData={setData} errors={errors} />

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={processing}>
                                Save
                            </Button>
                            <Button variant="link" asChild>
                                <Link href={route('departments.index')}>Cancel</Link>
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
