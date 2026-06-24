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

interface TypeOption {
    value: string;
    label: string;
}

export interface PermissionRecord {
    id: number;
    type: string;
    start_date: string;
    end_date: string;
    reason: string;
    has_attachment: boolean;
}

interface PermissionRequestFormDialogProps {
    record: PermissionRecord | null;
    types: TypeOption[];
    onClose: () => void;
}

export function PermissionRequestFormDialog({ record, types, onClose }: PermissionRequestFormDialogProps) {
    const isEdit = !!record;
    const { data, setData, post, processing, errors, transform } = useForm<{
        type: string;
        start_date: string;
        end_date: string;
        reason: string;
        attachment: File | null;
        _method?: string;
    }>({
        type: record?.type ?? types[0]?.value ?? '',
        start_date: record?.start_date ?? '',
        end_date: record?.end_date ?? '',
        reason: record?.reason ?? '',
        attachment: null,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // Inertia can't send files over PUT, so edits POST with a method-spoofed _method=PUT.
        transform((d) => (isEdit ? { ...d, _method: 'put' } : d));
        const url = isEdit ? route('permission-requests.update', record.id) : route('permission-requests.store');
        post(url, { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Request' : 'New Permission Request'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div>
                        <Label htmlFor="type">Type</Label>
                        <Select value={data.type} onValueChange={(value) => setData('type', value)}>
                            <SelectTrigger id="type" className="mt-1">
                                <SelectValue placeholder="Select type" />
                            </SelectTrigger>
                            <SelectContent>
                                {types.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.type} className="mt-2" />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label htmlFor="start_date">Start Date</Label>
                            <Input
                                id="start_date"
                                type="date"
                                value={data.start_date}
                                className="mt-1"
                                onChange={(e) => setData('start_date', e.target.value)}
                            />
                            <InputError message={errors.start_date} className="mt-2" />
                        </div>
                        <div>
                            <Label htmlFor="end_date">End Date</Label>
                            <Input
                                id="end_date"
                                type="date"
                                value={data.end_date}
                                className="mt-1"
                                onChange={(e) => setData('end_date', e.target.value)}
                            />
                            <InputError message={errors.end_date} className="mt-2" />
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="reason">Reason</Label>
                        <Textarea
                            id="reason"
                            value={data.reason}
                            className="mt-1"
                            rows={3}
                            placeholder="e.g. Medical appointment — doctor's note attached."
                            onChange={(e) => setData('reason', e.target.value)}
                        />
                        <InputError message={errors.reason} className="mt-2" />
                    </div>

                    <div>
                        <Label htmlFor="attachment">Attachment (optional)</Label>
                        <Input
                            id="attachment"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            className="mt-1"
                            onChange={(e) => setData('attachment', e.target.files?.[0] ?? null)}
                        />
                        {isEdit && record.has_attachment && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                A file is already attached. Upload a new one to replace it.
                            </p>
                        )}
                        <InputError message={errors.attachment} className="mt-2" />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {isEdit ? 'Save' : 'Submit'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
