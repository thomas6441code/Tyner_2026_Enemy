import { PropsWithChildren, ReactNode, useState } from 'react';

import { AppHeader } from '@/components/app-header';
import { AppSidebar } from '@/components/app-sidebar';

interface AppLayoutProps {
    header?: ReactNode;
}

export default function AppLayout({ header, children }: PropsWithChildren<AppLayoutProps>) {
    const [mobileNavOpen, setMobileNavOpen] = useState(false);

    return (
        <div className="flex min-h-screen bg-canvas text-foreground">
            <AppSidebar mobileOpen={mobileNavOpen} onClose={() => setMobileNavOpen(false)} />
            <div className="flex min-w-0 flex-1 flex-col">
                <AppHeader onMenuClick={() => setMobileNavOpen(true)} />
                <main className="flex-1 px-4 py-6 md:px-8">
                    {header && <div className="mb-6">{header}</div>}
                    {children}
                </main>
            </div>
        </div>
    );
}
