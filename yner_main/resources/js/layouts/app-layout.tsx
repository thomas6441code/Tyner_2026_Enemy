import { PropsWithChildren, ReactNode } from 'react';

import { AppHeader } from '@/components/app-header';
import { AppSidebar } from '@/components/app-sidebar';

interface AppLayoutProps {
    header?: ReactNode;
}

export default function AppLayout({ header, children }: PropsWithChildren<AppLayoutProps>) {
    return (
        <div className="flex min-h-screen flex-col bg-background">
            <AppHeader />
            <div className="flex flex-1">
                <AppSidebar />
                <div className="flex-1">
                    {header && <div className="border-b bg-background px-6 py-4">{header}</div>}
                    <main className="px-6 py-6">{children}</main>
                </div>
            </div>
        </div>
    );
}
