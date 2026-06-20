import { Link } from '@inertiajs/react';
import { CalendarCheck, ShieldCheck, Sparkles } from 'lucide-react';
import { PropsWithChildren } from 'react';

import { AuthIllustration } from '@/components/auth-illustration';
import { Logo } from '@/components/logo';

const features = [
    { icon: CalendarCheck, text: 'Biometric attendance, synced in real time' },
    { icon: ShieldCheck, text: 'Permissions & leave that never read as absent' },
    { icon: Sparkles, text: 'AI insights on anomalies and absenteeism' },
];

export default function GuestLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen bg-canvas text-foreground">
            {/* Brand / onboarding panel */}
            <div className="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-primary/5 p-10 lg:flex xl:w-3/5">
                <Link href="/">
                    <Logo className="text-2xl" />
                </Link>

                <div className="flex justify-center py-8">
                    <AuthIllustration />
                </div>

                <div>
                    <h2 className="max-w-md text-2xl font-bold leading-snug tracking-tight">
                        Attendance & permissions, finally in sync.
                    </h2>
                    <ul className="mt-5 space-y-3">
                        {features.map(({ icon: Icon, text }) => (
                            <li key={text} className="flex items-center gap-3 text-sm text-muted-foreground">
                                <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <Icon className="h-4 w-4" />
                                </span>
                                {text}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            {/* Form panel */}
            <div className="flex w-full flex-col items-center justify-center px-6 py-10 lg:w-1/2 xl:w-2/5">
                <div className="w-full max-w-sm">
                    <Link href="/" className="mb-8 inline-block lg:hidden">
                        <Logo className="text-2xl" />
                    </Link>
                    {children}
                </div>
            </div>
        </div>
    );
}
