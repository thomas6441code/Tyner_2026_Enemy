import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import { GoogleIcon } from '@/components/google-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/layouts/guest-layout';

interface LoginForm {
    email: string;
    password: string;
    remember: boolean;
}

export default function Login({ status, canResetPassword }: { status?: string; canResetPassword: boolean }) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Log in" />

            <h1 className="text-2xl font-bold tracking-tight">Welcome!</h1>
            <p className="mt-1 text-sm text-muted-foreground">Sign in to your account to continue</p>

            {status && (
                <div className="mt-4 rounded-lg bg-primary/10 px-4 py-2 text-sm font-medium text-primary">{status}</div>
            )}

            <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
                <div>
                    <Label htmlFor="email">Email Address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1.5"
                        autoFocus
                        autoComplete="username"
                        placeholder="Write an email address"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1.5"
                        autoComplete="current-password"
                        placeholder="Enter Password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="flex items-center justify-between">
                    <label htmlFor="remember" className="flex items-center gap-2 text-sm text-muted-foreground">
                        <input
                            id="remember"
                            type="checkbox"
                            name="remember"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="h-4 w-4 rounded border-input text-primary focus:ring-ring"
                        />
                        Remember me
                    </label>

                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Forgot password?
                        </Link>
                    )}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    Sign in
                </Button>
            </form>

            <div className="my-6 flex items-center gap-3 text-xs text-muted-foreground">
                <span className="h-px flex-1 bg-border" />
                Or continue with
                <span className="h-px flex-1 bg-border" />
            </div>

            <Button type="button" variant="outline" className="w-full gap-2">
                <GoogleIcon className="h-4 w-4" /> Google
            </Button>

            <p className="mt-6 text-center text-sm text-muted-foreground">
                Don't have an account?{' '}
                <Link href={route('register')} className="font-semibold text-primary underline-offset-4 hover:underline">
                    Sign up
                </Link>
            </p>
        </GuestLayout>
    );
}
