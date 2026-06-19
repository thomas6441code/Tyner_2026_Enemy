import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export function DeleteUserForm() {
    const [confirmingDeletion, setConfirmingDeletion] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        errors,
        reset,
    } = useForm({
        password: '',
    });

    const confirmDeletion = () => setConfirmingDeletion(true);

    const closeModal = () => {
        setConfirmingDeletion(false);
        reset();
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            errorBag: 'userDeletion',
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    return (
        <section>
            <header>
                <h2 className="text-base font-semibold">Delete Account</h2>
                <p className="text-sm text-muted-foreground">
                    Once your account is deleted, all of its resources and data will be permanently deleted. Before
                    deleting your account, please download any data or information that you wish to retain.
                </p>
            </header>

            <Button variant="destructive" size="sm" className="mt-4" onClick={confirmDeletion}>
                Delete Account
            </Button>

            <Dialog open={confirmingDeletion} onOpenChange={setConfirmingDeletion}>
                <DialogContent>
                    <form onSubmit={submit}>
                        <DialogHeader>
                            <DialogTitle>Are you sure you want to delete your account?</DialogTitle>
                            <DialogDescription>
                                Once your account is deleted, all of its resources and data will be permanently
                                deleted. Please enter your password to confirm you would like to permanently delete
                                your account.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="mt-4">
                            <Label htmlFor="password" className="sr-only">
                                Password
                            </Label>
                            <Input
                                id="password"
                                ref={passwordInput}
                                type="password"
                                name="password"
                                value={data.password}
                                placeholder="Password"
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            <InputError message={errors.password} className="mt-2" />
                        </div>

                        <DialogFooter className="mt-4">
                            <Button type="button" variant="outline" onClick={closeModal}>
                                Cancel
                            </Button>
                            <Button type="submit" variant="destructive" disabled={processing}>
                                Delete Account
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
