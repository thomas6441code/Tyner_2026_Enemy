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

interface Employee {
    id: number;
    employee_code: string;
    first_name: string;
    last_name: string;
    phone: string | null;
    hire_date: string | null;
    status: string;
    department_id: number | null;
    work_schedule_id: number | null;
    user_id: number | null;
}

interface EditEmployeeProps {
    employee: Employee;
    departments: Option[];
    workSchedules: Option[];
    unlinkedUsers: UserOption[];
}

export default function EditEmployee({ employee, departments, workSchedules, unlinkedUsers }: EditEmployeeProps) {
    const { data, setData, put, processing, errors } = useForm({
        employee_code: employee.employee_code,
        first_name: employee.first_name,
        last_name: employee.last_name,
        phone: employee.phone ?? '',
        hire_date: employee.hire_date ?? '',
        status: employee.status,
        department_id: employee.department_id ? String(employee.department_id) : '',
        work_schedule_id: employee.work_schedule_id ? String(employee.work_schedule_id) : '',
        user_id: employee.user_id ? String(employee.user_id) : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('employees.update', employee.id));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">Edit Employee</h2>}>
            <Head title="Edit Employee" />

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
                                Save
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
