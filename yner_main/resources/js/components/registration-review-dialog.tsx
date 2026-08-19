import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface Option {
    id: number;
    name: string;
}

interface RegistrationRow {
    id: number;
    name: string;
    email: string;
    department: string | null;
}

interface Props {
    record: RegistrationRow;
    nextEmployeeCode: string;
    departments: Option[];
    workSchedules: Option[];
    workLocations: Option[];
    onClose: () => void;
}

/**
 * Approving is where HR supplies everything the applicant cannot: a unique employee code,
 * department, work schedule and hire date. The attendance engine depends on all of them,
 * which is why the Employee record is created here rather than at activation.
 */
export function RegistrationApproveDialog({
    record,
    nextEmployeeCode,
    departments,
    workSchedules,
    workLocations,
    onClose,
}: Props) {
    const { data, setData, put, processing, errors } = useForm({
        department_id: '',
        work_schedule_id: '',
        work_location_id: '',
        hire_date: new Date().toISOString().slice(0, 10),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('registration-requests.approve', record.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Approve {record.name}</DialogTitle>
                        <DialogDescription>
                            This creates an inactive employee record and issues a single-use activation link to{' '}
                            {record.email}. The employee becomes active once they redeem it.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-4 flex flex-col gap-4">
<div>
                            {/*
                              Shown, not asked for. The server allocates the next code in
                              sequence on approval — a reviewer inventing a unique key mid-form
                              is how duplicates and typos got in, and the rejection landed at
                              the very end of an approval they had already committed to.
                            */}
                            <Label htmlFor="employee_code">Employee code</Label>
                            <Input
                                id="employee_code"
                                value={nextEmployeeCode}
                                className="mt-1 bg-muted text-muted-foreground"
                                readOnly
                                tabIndex={-1}
                            />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Assigned automatically when you approve.
                            </p>
                        </div>

                        <div>
                            <Label htmlFor="department_id">Department</Label>
                            <select
                                id="department_id"
                                autoFocus
                                value={data.department_id}
                                onChange={(e) => setData('department_id', e.target.value)}
                                className="mt-1 flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                            >
                                <option value="">Select a department</option>
                                {departments.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.department_id} className="mt-2" />
                        </div>

                        <div>
                            <Label htmlFor="work_schedule_id">Work schedule</Label>
                            <select
                                id="work_schedule_id"
                                value={data.work_schedule_id}
                                onChange={(e) => setData('work_schedule_id', e.target.value)}
                                className="mt-1 flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                            >
                                <option value="">Select a schedule</option>
                                {workSchedules.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.work_schedule_id} className="mt-2" />
                        </div>

                        <div>
                            <Label htmlFor="work_location_id">Work location</Label>
                            <select
                                id="work_location_id"
                                value={data.work_location_id}
                                onChange={(e) => setData('work_location_id', e.target.value)}
                                className="mt-1 flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                            >
                                <option value="">Inherit from department</option>
                                {workLocations.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.work_location_id} className="mt-2" />
                        </div>

                        <div>
                            <Label htmlFor="hire_date">Hire date</Label>
                            <Input
                                id="hire_date"
                                type="date"
                                value={data.hire_date}
                                className="mt-1"
                                onChange={(e) => setData('hire_date', e.target.value)}
                            />
                            <InputError message={errors.hire_date} className="mt-2" />
                        </div>
                    </div>

                    <DialogFooter className="mt-6 gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Approve &amp; issue link
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function RegistrationRejectDialog({ record, onClose }: { record: RegistrationRow; onClose: () => void }) {
    const { data, setData, put, processing, errors } = useForm({ review_note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('registration-requests.reject', record.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reject {record.name}</DialogTitle>
                        <DialogDescription>
                            The applicant is emailed this reason. No account or employee record is created.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-4">
                        <Label htmlFor="review_note">Reason</Label>
                        <Textarea
                            id="review_note"
                            value={data.review_note}
                            className="mt-1"
                            rows={3}
                            autoFocus
                            onChange={(e) => setData('review_note', e.target.value)}
                        />
                        <InputError message={errors.review_note} className="mt-2" />
                    </div>

                    <DialogFooter className="mt-6 gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Reject
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
