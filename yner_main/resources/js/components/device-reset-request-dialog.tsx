import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { clearDeviceToken } from '@/lib/device-token';

/**
 * An employee asking to move their account's binding onto a different phone.
 *
 * There is no self-service unlink, on purpose: if an employee could unlink and immediately
 * re-link, "one account, one device" would be undone by two clicks whenever someone wanted a
 * colleague to punch for them. The reason field is what a reviewer actually decides on, so it
 * is required and has a minimum length.
 */
export function DeviceResetRequestDialog({ onClose }: { onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('device-reset-requests.store'), {
            preserveScroll: true,
            onSuccess: () => {
                // This handset's mirrored token is about to become meaningless — the device it
                // belongs to is on its way out. Dropping it now keeps a stale value from being
                // presented on the new phone if the browser profile is transferred.
                clearDeviceToken();
                onClose();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Request a device reset</DialogTitle>
                        <DialogDescription>
                            An administrator or HR officer will review this. If they approve, your current
                            phone is unlinked and you get a short window to link the new one — you cannot
                            check in during that gap, so only ask when you genuinely need to.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-4">
                        <Label htmlFor="reason">Why do you need a different device?</Label>
                        <Textarea
                            id="reason"
                            value={data.reason}
                            className="mt-1"
                            rows={4}
                            autoFocus
                            maxLength={1000}
                            placeholder="For example: my phone was stolen on 18 August and I have replaced it."
                            onChange={(e) => setData('reason', e.target.value)}
                        />
                        <InputError message={errors.reason} className="mt-2" />
                    </div>

                    <DialogFooter className="mt-6 gap-2">
                        <Button type="button" variant="outline" onClick={onClose} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Sending…' : 'Send request'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
