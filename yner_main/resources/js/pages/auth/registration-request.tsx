import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import GuestLayout from '@/layouts/guest-layout';

interface Department {
    id: number;
    name: string;
}

export default function RegistrationRequest({ departments }: { departments: Department[] }) {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        department_id: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'));
    };

    return (
        <GuestLayout>
            <Head title="Request Access" />

            <div className="mb-4 px-2 text-sm text-muted-foreground">
                Submitting this form does not create an account. An administrator reviews your details and, once
                approved, emails you a single-use link to set your password.
            </div>

            <form onSubmit={submit} className="flex flex-col gap-4 px-2">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="first_name">First name</Label>
                        <Input
                            id="first_name"
                            value={data.first_name}
                            className="mt-1"
                            autoFocus
                            autoComplete="given-name"
                            onChange={(e) => setData('first_name', e.target.value)}
                        />
                        <InputError message={errors.first_name} className="mt-2" />
                    </div>

                    <div>
                        <Label htmlFor="last_name">Last name</Label>
                        <Input
                            id="last_name"
                            value={data.last_name}
                            className="mt-1"
                            autoComplete="family-name"
                            onChange={(e) => setData('last_name', e.target.value)}
                        />
                        <InputError message={errors.last_name} className="mt-2" />
                    </div>
                </div>

                <div>
                    <Label htmlFor="email">
                        Email <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        className="mt-1"
                        required
                        autoComplete="email"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-muted-foreground">
                        Your activation link is sent here, so it must be an address you can actually open — a Gmail,
                        Outlook, or organisation account. Addresses whose domain accepts no mail are rejected.
                    </p>
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="phone">Phone (optional)</Label>
                    <Input
                        id="phone"
                        value={data.phone}
                        className="mt-1"
                        autoComplete="tel"
                        onChange={(e) => setData('phone', e.target.value)}
                    />
                    <InputError message={errors.phone} className="mt-2" />
                </div>

                <div className='hidden'>
                    <Label htmlFor="department_id">Department (optional)</Label>
                    <select
                        id="department_id"
                        value={data.department_id}
                        onChange={(e) => setData('department_id', e.target.value)}
                        className="mt-1 flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    >
                        <option value="">Select a department</option>
                        {departments.map((department) => (
                            <option key={department.id} value={department.id}>
                                {department.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.department_id} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="note">Anything we should know? (optional)</Label>
                    <Textarea
                        id="note"
                        value={data.note}
                        className="mt-1"
                        rows={3}
                        onChange={(e) => setData('note', e.target.value)}
                    />
                    <InputError message={errors.note} className="mt-2" />
                </div>

                <div className="flex items-center justify-end">
                    <Button type="submit" disabled={processing}>
                        Submit request
                    </Button>
                </div>
            </form>

            <p className="mt-6 text-center text-sm text-muted-foreground">
                Already have an account?{' '}
                <Link href={route('login')} className="font-semibold text-primary underline-offset-4 hover:underline">
                    Sign in
                </Link>
            </p>
        </GuestLayout>
    );
}
