import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { DepartmentForm } from '@/components/department-form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface Option {
    id: number;
    name: string;
}

interface Department {
    id: number;
    name: string;
    description: string | null;
    work_location_id: number | null;
}

interface DepartmentFormDialogProps {
    record: Department | null;
    workLocations: Option[];
    onClose: () => void;
}

export function DepartmentFormDialog({ record, workLocations, onClose }: DepartmentFormDialogProps) {
    const isEdit = !!record;
    const { data, setData, post, put, processing, errors } = useForm({
        name: record?.name ?? '',
        description: record?.description ?? '',
        work_location_id: record?.work_location_id ? String(record.work_location_id) : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) {
            put(route('departments.update', record.id), options);
        } else {
            post(route('departments.store'), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Department' : 'New Department'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DepartmentForm data={data} setData={setData} errors={errors} workLocations={workLocations} />
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
