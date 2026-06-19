import { Head, Link, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';

import AppLogo from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col items-center justify-center gap-4 px-3 text-center">
                <AppLogo className="mb-4 h-20 w-20 text-foreground/70" />

                <h1 className="text-2xl font-semibold">EAPMS</h1>
                <p className="max-w-md text-muted-foreground">
                    Employee Attendance &amp; Permission Management System — biometric attendance and HR
                    permission/leave workflows, synchronized.
                </p>

                <div className="flex gap-2">
                    {auth.user ? (
                        <Button asChild>
                            <Link href={route('dashboard')}>Dashboard</Link>
                        </Button>
                    ) : (
                        <>
                            <Button asChild>
                                <Link href={route('login')}>Log in</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={route('register')}>Register</Link>
                            </Button>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
