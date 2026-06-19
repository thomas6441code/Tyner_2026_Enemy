import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { BiometricDeviceForm } from '@/components/biometric-device-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

interface BiometricDevice {
    id: number;
    name: string;
    type: string;
    serial: string;
    host: string | null;
    port: number | null;
    username: string | null;
    status: string;
}

export default function EditBiometricDevice({ device }: { device: BiometricDevice }) {
    const { data, setData, put, processing, errors } = useForm({
        name: device.name,
        type: device.type,
        serial: device.serial,
        host: device.host ?? '',
        port: device.port ? String(device.port) : '',
        username: device.username ?? '',
        password: '',
        status: device.status,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('biometric-devices.update', device.id));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">Edit Biometric Device</h2>}>
            <Head title="Edit Biometric Device" />

            <Card className="max-w-xl">
                <CardContent className="p-6">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <BiometricDeviceForm data={data} setData={setData} errors={errors} isEdit />

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={processing}>
                                Save
                            </Button>
                            <Button variant="link" asChild>
                                <Link href={route('biometric-devices.index')}>Cancel</Link>
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
