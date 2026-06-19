import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { WorkScheduleForm } from '@/components/work-schedule-form';
import AppLayout from '@/layouts/app-layout';

interface WorkSchedule {
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    grace_period_minutes: number;
}

export default function EditWorkSchedule({ workSchedule }: { workSchedule: WorkSchedule }) {
    const { data, setData, put, processing, errors } = useForm({
        name: workSchedule.name,
        start_time: workSchedule.start_time,
        end_time: workSchedule.end_time,
        grace_period_minutes: String(workSchedule.grace_period_minutes),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('work-schedules.update', workSchedule.id));
    };

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">Edit Work Schedule</h2>}>
            <Head title="Edit Work Schedule" />

            <Card className="max-w-lg">
                <CardContent className="p-6">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <WorkScheduleForm data={data} setData={setData} errors={errors} />

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={processing}>
                                Save
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
