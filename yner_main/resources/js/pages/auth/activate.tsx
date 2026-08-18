import { Head, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/layouts/guest-layout';

interface Identity {
    name: string;
    email: string;
    employee_code: string;
    department: string | null;
}

interface ActivateProps {
    uuid: string;
    token: string;
    identity: Identity;
    expiresAt: string;
}

/**
 * Identity fields are rendered read-only because they were fixed when an administrator
 * approved the registration request. Activation only ever sets a password.
 */
function ReadOnlyField({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <Label className="flex items-center gap-1.5 text-muted-foreground">
                {label}
                <Lock className="h-3 w-3" />
            </Label>
            <Input value={value} readOnly disabled className="mt-1" />
        </div>
    );
}

export default function Activate({ uuid, token, identity, expiresAt }: ActivateProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('account.activate.store', { uuid, token }), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Create Your Account" />

            <div className="mb-4 px-2">
                <h1 className="text-lg font-semibold">Create your EAPMS account</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    Your registration was approved. Choose a password to finish setting up your account. This link can
                    only be used once and expires on {expiresAt}.
                </p>
            </div>

            <form onSubmit={submit} className="flex flex-col gap-4 px-2">
                <ReadOnlyField label="Name" value={identity.name} />
                <ReadOnlyField label="Employee code" value={identity.employee_code} />
                <ReadOnlyField label="Email" value={identity.email} />
                {identity.department && <ReadOnlyField label="Department" value={identity.department} />}

                <div>
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        className="mt-1"
                        autoFocus
                        autoComplete="new-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        value={data.password_confirmation}
                        className="mt-1"
                        autoComplete="new-password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} className="mt-2" />
                </div>

                <div className="flex items-center justify-end">
                    <Button type="submit" disabled={processing}>
                        Create account
                    </Button>
                </div>
            </form>
        </GuestLayout>
    );
}
