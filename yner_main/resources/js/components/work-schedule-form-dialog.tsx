import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { WorkScheduleForm } from '@/components/work-schedule-form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface WorkSchedule {
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    grace_period_minutes: number;
}

interface WorkScheduleFormDialogProps {
    record: WorkSchedule | null;
    onClose: () => void;
}

export function WorkScheduleFormDialog({ record, onClose }: WorkScheduleFormDialogProps) {
    const isEdit = !!record;
    const { data, setData, post, put, processing, errors } = useForm({
        name: record?.name ?? '',
        start_time: record?.start_time ?? '08:00',
        end_time: record?.end_time ?? '17:00',
        grace_period_minutes: record ? String(record.grace_period_minutes) : '15',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) {
            put(route('work-schedules.update', record.id), options);
        } else {
            post(route('work-schedules.store'), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Work Schedule' : 'New Work Schedule'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <WorkScheduleForm data={data} setData={setData} errors={errors} />
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {isEdit ? 'Save' : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
