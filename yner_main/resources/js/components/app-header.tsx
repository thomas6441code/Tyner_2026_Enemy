import { Link, router, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';

import AppLogo from '@/components/app-logo';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SharedData } from '@/types';

export function AppHeader() {
    const { auth } = usePage<SharedData>().props;
    const user = auth.user;

    return (
        <header className="flex h-14 items-center justify-between border-b bg-background px-4">
            <Link href={route('dashboard')} className="flex items-center gap-2 font-semibold">
                <AppLogo className="h-6 w-6" />
                EAPMS
            </Link>

            {user && (
                <DropdownMenu>
                    <DropdownMenuTrigger className="flex items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-accent">
                        <Avatar className="h-7 w-7">
                            <AvatarFallback>{user.name.charAt(0).toUpperCase()}</AvatarFallback>
                        </Avatar>
                        {user.name}
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            <Link href={route('profile.edit')}>Profile</Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => router.post(route('logout'))}>
                            Log Out
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </header>
    );
}
