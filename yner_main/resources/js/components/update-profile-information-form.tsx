import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SharedData } from '@/types';

export function UpdateProfileInformationForm({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const user = usePage<SharedData>().props.auth.user!;

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        name: user.name,
        email: user.email,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('profile.update'));
    };

    return (
        <section>
            <header>
                <h2 className="text-base font-semibold">Profile Information</h2>
                <p className="text-sm text-muted-foreground">
                    Update your account&apos;s profile information and email address.
                </p>
            </header>

            <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                <div>
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1"
                        autoFocus
                        autoComplete="name"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1"
                        autoComplete="username"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-2" />

                    {mustVerifyEmail && (
                        <div className="mt-2 text-sm text-muted-foreground">
                            Your email address is unverified.
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="ml-1 underline-offset-4 hover:underline"
                            >
                                Click here to re-send the verification email.
                            </Link>
                            {status === 'verification-link-sent' && (
                                <p className="mt-2 font-medium text-emerald-600">
                                    A new verification link has been sent to your email address.
                                </p>
                            )}
                        </div>
                    )}
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
