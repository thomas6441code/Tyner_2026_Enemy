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

interface Enrollment {
    id: number;
    biometric_device_id: number;
    employee_id: number;
    device_user_id: string;
}

interface EditDeviceEnrollmentProps {
    enrollment: Enrollment;
    devices: DeviceOption[];
    employees: EmployeeOption[];
}

export default function EditDeviceEnrollment({ enrollment, devices, employees }: EditDeviceEnrollmentProps) {
    const { data, setData, put, processing, errors } = useForm({
        biometric_device_id: String(enrollment.biometric_device_id),
        employee_id: String(enrollment.employee_id),
        device_user_id: enrollment.device_user_id,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('device-enrollments.update', enrollment.id));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">Edit Device Enrollment</h2>}>
            <Head title="Edit Device Enrollment" />

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
                                Save
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
