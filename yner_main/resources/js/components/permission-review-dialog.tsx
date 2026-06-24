import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export interface ReviewRecord {
    id: number;
    employee: string | null;
    type_label: string;
    start_date: string;
    end_date: string;
    reason: string;
    has_attachment: boolean;
}

interface PermissionReviewDialogProps {
    record: ReviewRecord;
    onClose: () => void;
}

export function PermissionReviewDialog({ record, onClose }: PermissionReviewDialogProps) {
    const { data, setData, put, transform, processing, errors } = useForm<{ decision: string; review_note: string }>({
        decision: 'approved',
        review_note: '',
    });

    const submit = (decision: 'approved' | 'rejected'): FormEventHandler => (e) => {
        e.preventDefault();
        // The decision comes from which button was clicked, not form state — inject it via transform.
        transform((d) => ({ ...d, decision }));
        put(route('permission-requests.review', record.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Review Request</DialogTitle>
                </DialogHeader>

                <dl className="grid grid-cols-3 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">Employee</dt>
                    <dd className="col-span-2">{record.employee ?? '—'}</dd>
                    <dt className="text-muted-foreground">Type</dt>
                    <dd className="col-span-2">{record.type_label}</dd>
                    <dt className="text-muted-foreground">Dates</dt>
                    <dd className="col-span-2">
                        {record.start_date} → {record.end_date}
                    </dd>
                    <dt className="text-muted-foreground">Reason</dt>
                    <dd className="col-span-2">{record.reason}</dd>
                    {record.has_attachment && (
                        <>
                            <dt className="text-muted-foreground">Attachment</dt>
                            <dd className="col-span-2">
                                <a
                                    href={route('permission-requests.attachment', record.id)}
                                    className="text-primary underline"
                                >
                                    Download
                                </a>
                            </dd>
                        </>
                    )}
                </dl>

                <form className="mt-2 flex flex-col gap-4">
                    <div>
                        <Label htmlFor="review_note">Note (required when rejecting)</Label>
                        <Textarea
                            id="review_note"
                            value={data.review_note}
                            className="mt-1"
                            rows={3}
                            placeholder="Optional note for approval; required reason for rejection."
                            onChange={(e) => setData('review_note', e.target.value)}
                        />
                        <InputError message={errors.review_note} className="mt-2" />
                        <InputError message={errors.decision} className="mt-2" />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={processing}
                            onClick={(e) => submit('rejected')(e)}
                        >
                            Reject
                        </Button>
                        <Button type="button" disabled={processing} onClick={(e) => submit('approved')(e)}>
                            Approve
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
