import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { WorkScheduleForm } from '@/components/work-schedule-form';
import AppLayout from '@/layouts/app-layout';

export default function CreateWorkSchedule() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        start_time: '',
        end_time: '',
        grace_period_minutes: '15',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('work-schedules.store'));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">New Work Schedule</h2>}>
            <Head title="New Work Schedule" />

            <Card className="max-w-lg">
                <CardContent className="p-6">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <WorkScheduleForm data={data} setData={setData} errors={errors} />

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={processing}>
                                Create
                            </Button>
                            <Button variant="link" asChild>
                                <Link href={route('work-schedules.index')}>Cancel</Link>
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
