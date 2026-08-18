import { Head, Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { route } from 'ziggy-js';

import GuestLayout from '@/layouts/guest-layout';

export default function RegistrationSubmitted() {
    return (
        <GuestLayout>
            <Head title="Request Submitted" />

            <div className="flex flex-col items-center gap-4 px-2 py-6 text-center">
                <CheckCircle2 className="h-12 w-12 text-emerald-600" />

                <h1 className="text-lg font-semibold">Request submitted</h1>

                <p className="text-sm text-muted-foreground">
                    Your registration request has been sent to the EAPMS administrators for review. If it is approved,
                    you will receive an email with a single-use link to create your account.
                </p>

                <p className="text-sm text-muted-foreground">
                    No account has been created yet, and you cannot sign in until your request is approved.
                </p>

                <Link
                    href={route('login')}
                    className="mt-2 text-sm font-semibold text-primary underline-offset-4 hover:underline"
                >
                    Back to sign in
                </Link>
            </div>
        </GuestLayout>
    );
}
