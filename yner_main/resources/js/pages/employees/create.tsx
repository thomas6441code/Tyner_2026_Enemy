import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { EmployeeForm } from '@/components/employee-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

interface Option {
    id: number;
    name: string;
}

interface UserOption {
    id: number;
    name: string;
    email: string;
}

interface CreateEmployeeProps {
    departments: Option[];
    workSchedules: Option[];
    unlinkedUsers: UserOption[];
}

export default function CreateEmployee({ departments, workSchedules, unlinkedUsers }: CreateEmployeeProps) {
    const { data, setData, post, processing, errors } = useForm({
        employee_code: '',
        first_name: '',
        last_name: '',
        phone: '',
        hire_date: '',
        status: 'active',
        department_id: '',
        work_schedule_id: '',
        user_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('employees.store'));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">New Employee</h2>}>
            <Head title="New Employee" />

            <Card className="max-w-xl">
                <CardContent className="p-6">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <EmployeeForm
                            data={data}
                            setData={setData}
                            errors={errors}
                            departments={departments}
                            workSchedules={workSchedules}
                            unlinkedUsers={unlinkedUsers}
                        />

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={processing}>
                                Create
                            </Button>
                            <Button variant="link" asChild>
                                <Link href={route('employees.index')}>Cancel</Link>
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
