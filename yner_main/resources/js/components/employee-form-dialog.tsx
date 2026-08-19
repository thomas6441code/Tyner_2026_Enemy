import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { EmployeeForm } from '@/components/employee-form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface Option {
    id: number;
    name: string;
}

interface UserOption {
    id: number;
    name: string;
    email: string;
}

export interface EmployeeRecord {
    id: number;
    employee_code: string;
    first_name: string;
    last_name: string;
    phone: string | null;
    hire_date: string | null;
    status: string;
    department_id: number | null;
    work_schedule_id: number | null;
    work_location_id: number | null;
    user_id: number | null;
}

interface EmployeeFormDialogProps {
    record: EmployeeRecord | null;
    departments: Option[];
    workSchedules: Option[];
    workLocations: Option[];
    unlinkedUsers: UserOption[];
    nextEmployeeCode: string;
    onClose: () => void;
}

export function EmployeeFormDialog({
    record,
    departments,
    workSchedules,
    workLocations,
    unlinkedUsers,
    nextEmployeeCode,
    onClose,
}: EmployeeFormDialogProps) {
    const isEdit = !!record;
    const { data, setData, post, put, processing, errors } = useForm({
        first_name: record?.first_name ?? '',
        last_name: record?.last_name ?? '',
        phone: record?.phone ?? '',
        hire_date: record?.hire_date ?? '',
        status: record?.status ?? 'active',
        department_id: record?.department_id ? String(record.department_id) : '',
        work_schedule_id: record?.work_schedule_id ? String(record.work_schedule_id) : '',
        work_location_id: record?.work_location_id ? String(record.work_location_id) : '',
        user_id: record?.user_id ? String(record.user_id) : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) {
            put(route('employees.update', record.id), options);
        } else {
            post(route('employees.store'), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Employee' : 'New Employee'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <EmployeeForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        departments={departments}
                        workSchedules={workSchedules}
                        workLocations={workLocations}
                        unlinkedUsers={unlinkedUsers}
                        employeeCode={record?.employee_code ?? nextEmployeeCode}
                        isEdit={isEdit}
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
