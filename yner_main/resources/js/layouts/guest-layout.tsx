import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

import AppLogo from '@/components/app-logo';
import { Card, CardContent } from '@/components/ui/card';

export default function GuestLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background px-4 py-4">
            <Link href="/" className="mb-2">
                <AppLogo className="h-16 w-16 text-foreground/70" />
            </Link>

            <Card className="w-full max-w-md">
                <CardContent className="p-6">{children}</CardContent>
            </Card>
        </div>
    );
}
