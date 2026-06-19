import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export function UpdatePasswordForm() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const { data, setData, put, processing, errors, reset, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            errorBag: 'updatePassword',
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <section>
            <header>
                <h2 className="text-base font-semibold">Update Password</h2>
                <p className="text-sm text-muted-foreground">
                    Ensure your account is using a long, random password to stay secure.
                </p>
            </header>

            <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                <div>
                    <Label htmlFor="current_password">Current Password</Label>
                    <Input
                        id="current_password"
                        ref={currentPasswordInput}
                        type="password"
                        name="current_password"
                        value={data.current_password}
                        className="mt-1"
                        autoComplete="current-password"
                        onChange={(e) => setData('current_password', e.target.value)}
                    />
                    <InputError message={errors.current_password} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="password">New Password</Label>
                    <Input
                        id="password"
                        ref={passwordInput}
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1"
                        autoComplete="new-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="password_confirmation">Confirm Password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className="mt-1"
                        autoComplete="new-password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} className="mt-2" />
                </div>

                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={processing}>
                        Save
                    </Button>

                    {recentlySuccessful && <p className="text-sm text-muted-foreground">Saved.</p>}
                </div>
            </form>
        </section>
    );
}
