import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { DeviceEnrollmentForm } from '@/components/device-enrollment-form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface DeviceOption {
    id: number;
    name: string;
    serial: string;
}

interface EmployeeOption {
    id: number;
    fullName: string;
}

export interface DeviceEnrollmentRecord {
    id: number;
    biometric_device_id: number;
    employee_id: number;
    device_user_id: string;
}

interface DeviceEnrollmentFormDialogProps {
    record: DeviceEnrollmentRecord | null;
    devices: DeviceOption[];
    employees: EmployeeOption[];
    onClose: () => void;
}

export function DeviceEnrollmentFormDialog({
    record,
    devices,
    employees,
    onClose,
}: DeviceEnrollmentFormDialogProps) {
    const isEdit = !!record;
    const { data, setData, post, put, processing, errors } = useForm({
        biometric_device_id: record?.biometric_device_id ? String(record.biometric_device_id) : '',
        employee_id: record?.employee_id ? String(record.employee_id) : '',
        device_user_id: record?.device_user_id ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) {
            put(route('device-enrollments.update', record.id), options);
        } else {
            post(route('device-enrollments.store'), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Enrollment' : 'New Enrollment'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DeviceEnrollmentForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        devices={devices}
                        employees={employees}
                    />
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
