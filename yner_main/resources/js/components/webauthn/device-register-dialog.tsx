import { router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PASSWORD_CONFIRMATION_REQUIRED, useWebAuthn } from '@/components/webauthn/use-webauthn';

export function DeviceRegisterDialog({ onClose }: { onClose: () => void }) {
    const { register, busy } = useWebAuthn();
    const [deviceName, setDeviceName] = useState(defaultDeviceName());
    const [error, setError] = useState<string | null>(null);

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setError(null);

        if (deviceName.trim() === '') {
            setError('Give this device a name so you can recognise it later.');
            return;
        }

        const result = await register(deviceName.trim());

        if (result.message === PASSWORD_CONFIRMATION_REQUIRED) {
            // Registering an authenticator is gated behind a password re-prompt. Send them
            // there rather than showing a dead end.
            router.visit(route('password.confirm'));
            return;
        }

        if (!result.ok) {
            setError(result.message);
            return;
        }

        router.reload({ only: ['devices', 'stats'] });
        onClose();
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Register this device</DialogTitle>
                        <DialogDescription>
                            Your phone will ask for your fingerprint or face. Nothing biometric leaves the
                            device — it only proves this device is yours when you check in.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-4">
                        <Label htmlFor="device_name">Device name</Label>
                        <Input
                            id="device_name"
                            value={deviceName}
                            className="mt-1"
                            autoFocus
                            maxLength={100}
                            onChange={(e) => setDeviceName(e.target.value)}
                        />
                        <InputError message={error ?? undefined} className="mt-2" />
                    </div>

                    <DialogFooter className="mt-6 gap-2">
                        <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy}>
                            {busy ? 'Waiting for your device…' : 'Register'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A rough guess from the user agent, purely to save typing — the field stays editable.
 */
function defaultDeviceName(): string {
    const ua = navigator.userAgent;

    if (/iPhone/i.test(ua)) return 'My iPhone';
    if (/iPad/i.test(ua)) return 'My iPad';
    if (/Android/i.test(ua)) return 'My Android phone';
    if (/Macintosh/i.test(ua)) return 'My Mac';
    if (/Windows/i.test(ua)) return 'My Windows PC';

    return 'My device';
}
