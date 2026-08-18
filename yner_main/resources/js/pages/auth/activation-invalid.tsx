import { Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { route } from 'ziggy-js';

import GuestLayout from '@/layouts/guest-layout';

type Reason = 'invalid' | 'used' | 'expired' | 'revoked';

const COPY: Record<Reason, { title: string; body: string }> = {
    invalid: {
        title: 'This link is not valid',
        body: 'The activation link could not be recognised. Check that you copied the whole link from your email — it is long and easy to truncate.',
    },
    used: {
        title: 'This invitation has already been used',
        body: 'An account was already created with this link. Activation links work only once. If the account is not yours, contact an administrator immediately.',
    },
    expired: {
        title: 'This invitation has expired',
        body: 'Activation links are time-limited. Ask an administrator to send you a new one — they can re-issue it from the invitations page.',
    },
    revoked: {
        title: 'This invitation was revoked',
        body: 'An administrator cancelled this activation link. Contact them if you still need access.',
    },
};

export default function ActivationInvalid({ reason }: { reason: Reason }) {
    const { title, body } = COPY[reason] ?? COPY.invalid;

    return (
        <GuestLayout>
            <Head title="Invitation Unavailable" />

            <div className="flex flex-col items-center gap-4 px-2 py-6 text-center">
                <AlertTriangle className="h-12 w-12 text-amber-500" />

                <h1 className="text-lg font-semibold">{title}</h1>

                <p className="text-sm text-muted-foreground">{body}</p>

                <div className="mt-2 flex flex-col gap-2 text-sm">
                    <Link
                        href={route('register')}
                        className="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        Submit a new registration request
                    </Link>
                    <Link href={route('login')} className="text-muted-foreground underline-offset-4 hover:underline">
                        Back to sign in
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
