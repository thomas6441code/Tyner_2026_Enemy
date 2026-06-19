import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { Button } from '@/components/ui/button';
import GuestLayout from '@/layouts/guest-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Email Verification" />

            <div className="mb-4 text-sm text-muted-foreground">
                Thanks for signing up! Before getting started, could you verify your email address by clicking on
                the link we just emailed to you? If you didn&apos;t receive the email, we will gladly send you
                another.
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-sm font-medium text-emerald-600">
                    A new verification link has been sent to the email address you provided during registration.
                </div>
            )}

            <div className="flex items-center justify-between">
                <form onSubmit={submit}>
                    <Button type="submit" disabled={processing}>
                        Resend Verification Email
                    </Button>
                </form>

                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    Log Out
                </Link>
            </div>
        </GuestLayout>
    );
}
