import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { BiometricDeviceForm } from '@/components/biometric-device-form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export interface BiometricDeviceRecord {
    id: number;
    name: string;
    type: string;
    serial: string;
    host: string | null;
    port: number | null;
    username: string | null;
    status: string;
}

interface BiometricDeviceFormDialogProps {
    record: BiometricDeviceRecord | null;
    onClose: () => void;
}

export function BiometricDeviceFormDialog({ record, onClose }: BiometricDeviceFormDialogProps) {
    const isEdit = !!record;
    const { data, setData, post, put, processing, errors } = useForm({
        name: record?.name ?? '',
        type: record?.type ?? 'stub',
        serial: record?.serial ?? '',
        host: record?.host ?? '',
        port: record?.port ? String(record.port) : '',
        username: record?.username ?? '',
        password: '',
        status: record?.status ?? 'active',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) {
            put(route('biometric-devices.update', record.id), options);
        } else {
            post(route('biometric-devices.store'), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Device' : 'New Device'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <BiometricDeviceForm data={data} setData={setData} errors={errors} isEdit={isEdit} />
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
