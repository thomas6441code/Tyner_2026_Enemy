import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { DeviceEnrollmentForm } from '@/components/device-enrollment-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

interface DeviceOption {
    id: number;
    name: string;
    serial: string;
}

interface EmployeeOption {
    id: number;
    fullName: string;
}

interface CreateDeviceEnrollmentProps {
    devices: DeviceOption[];
    employees: EmployeeOption[];
}

export default function CreateDeviceEnrollment({ devices, employees }: CreateDeviceEnrollmentProps) {
    const { data, setData, post, processing, errors } = useForm({
        biometric_device_id: '',
        employee_id: '',
        device_user_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('device-enrollments.store'));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">New Device Enrollment</h2>}>
            <Head title="New Device Enrollment" />

            <Card className="max-w-xl">
                <CardContent className="p-6">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DeviceEnrollmentForm
                            data={data}
                            setData={setData}
                            errors={errors}
                            devices={devices}
                            employees={employees}
                        />

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={processing}>
                                Create
                            </Button>
                            <Button variant="link" asChild>
                                <Link href={route('device-enrollments.index')}>Cancel</Link>
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
