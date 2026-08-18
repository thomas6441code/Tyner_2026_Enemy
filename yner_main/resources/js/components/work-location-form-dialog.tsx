import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { WorkLocationForm } from '@/components/work-location-form';

export interface WorkLocationRecord {
    id: number;
    name: string;
    address: string | null;
    latitude: number;
    longitude: number;
    radius_meters: number;
    is_active: boolean;
}

interface WorkLocationFormDialogProps {
    record: WorkLocationRecord | null;
    onClose: () => void;
}

export function WorkLocationFormDialog({ record, onClose }: WorkLocationFormDialogProps) {
    const isEdit = !!record;
    const { data, setData, post, put, processing, errors } = useForm({
        name: record?.name ?? '',
        address: record?.address ?? '',
        latitude: record ? String(record.latitude) : '',
        longitude: record ? String(record.longitude) : '',
        radius_meters: record ? String(record.radius_meters) : '150',
        is_active: record ? (record.is_active ? '1' : '0') : '1',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) {
            put(route('work-locations.update', record.id), options);
        } else {
            post(route('work-locations.store'), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Work Location' : 'New Work Location'}</DialogTitle>
                    <DialogDescription>
                        Employees assigned here may check in from a phone only while inside this radius.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <WorkLocationForm data={data} setData={setData} errors={errors} />
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
