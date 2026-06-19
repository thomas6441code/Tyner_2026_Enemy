import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { BiometricDeviceForm } from '@/components/biometric-device-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

export default function CreateBiometricDevice() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        type: 'stub',
        serial: '',
        host: '',
        port: '',
        username: '',
        password: '',
        status: 'active',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('biometric-devices.store'));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">New Biometric Device</h2>}>
            <Head title="New Biometric Device" />

            <Card className="max-w-xl">
                <CardContent className="p-6">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <BiometricDeviceForm data={data} setData={setData} errors={errors} />

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={processing}>
                                Create
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
