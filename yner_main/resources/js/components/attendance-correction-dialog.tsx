import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

export interface AttendanceCorrectionRecord {
    id: number;
    status: string;
    first_in: string | null;
    last_out: string | null;
    remarks: string | null;
}

interface StatusOption {
    value: string;
    label: string;
}

interface AttendanceCorrectionDialogProps {
    record: AttendanceCorrectionRecord;
    employeeName: string;
    dayLabel: string;
    statusOptions: StatusOption[];
    onClose: () => void;
}

export function AttendanceCorrectionDialog({
    record,
    employeeName,
    dayLabel,
    statusOptions,
    onClose,
}: AttendanceCorrectionDialogProps) {
    const { data, setData, put, processing, errors } = useForm({
        status: record.status,
        first_in: record.first_in ?? '',
        last_out: record.last_out ?? '',
        remarks: record.remarks ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('attendance.update', record.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Correct Attendance</DialogTitle>
                </DialogHeader>
                <p className="-mt-2 text-sm text-muted-foreground">
                    {employeeName} — {dayLabel}
                </p>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div>
                        <Label htmlFor="status">Status</Label>
                        <Select value={data.status} onValueChange={(value) => setData('status', value)}>
                            <SelectTrigger id="status" className="mt-1">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {statusOptions.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.status} className="mt-2" />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label htmlFor="first_in">First In</Label>
                            <Input
                                id="first_in"
                                name="first_in"
                                type="time"
                                value={data.first_in}
                                className="mt-1"
                                onChange={(e) => setData('first_in', e.target.value)}
                            />
                            <InputError message={errors.first_in} className="mt-2" />
                        </div>

                        <div>
                            <Label htmlFor="last_out">Last Out</Label>
                            <Input
                                id="last_out"
                                name="last_out"
                                type="time"
                                value={data.last_out}
                                className="mt-1"
                                onChange={(e) => setData('last_out', e.target.value)}
                            />
                            <InputError message={errors.last_out} className="mt-2" />
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="remarks">Reason for correction</Label>
                        <Textarea
                            id="remarks"
                            name="remarks"
                            value={data.remarks}
                            className="mt-1"
                            rows={3}
                            placeholder="e.g. Approved sick leave — documentation on file."
                            onChange={(e) => setData('remarks', e.target.value)}
                        />
                        <InputError message={errors.remarks} className="mt-2" />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save Correction
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
