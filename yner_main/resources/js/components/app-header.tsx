import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Menu, MessageSquare, Search } from 'lucide-react';
import { route } from 'ziggy-js';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SharedData } from '@/types';

export function AppHeader({ onMenuClick }: { onMenuClick: () => void }) {
    const { auth, unreadNotifications } = usePage<SharedData>().props;
    const user = auth.user;
    const role = user?.roles?.[0];

    return (
        <header className="sticky top-0 z-30 flex h-20 items-center gap-3 bg-background/80 px-4 shadow-soft backdrop-blur md:gap-4 md:px-6">
            <button
                type="button"
                onClick={onMenuClick}
                aria-label="Open menu"
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground md:hidden"
            >
                <Menu className="h-5 w-5" />
            </button>

            <div className="relative w-full max-w-sm">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    type="search"
                    placeholder="Search anything…"
                    className="h-9 w-full rounded-lg border border-input bg-muted/50 pl-9 pr-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:bg-background focus:ring-1 focus:ring-ring"
                />
            </div>

            <div className="ml-auto flex items-center gap-1">
                <button
                    type="button"
                    className="relative flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    aria-label="Messages"
                >
                    <MessageSquare className="h-4.5 w-4.5" />
                </button>
                <Link
                    href={route('notifications.index')}
                    className="relative flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    aria-label="Notifications"
                >
                    <Bell className="h-4.5 w-4.5" />
                    {unreadNotifications > 0 && (
                        <span className="absolute right-1.5 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-none text-destructive-foreground">
                            {unreadNotifications > 9 ? '9+' : unreadNotifications}
                        </span>
                    )}
                </Link>

                {user && (
                    <DropdownMenu>
                        <DropdownMenuTrigger className="ml-1 flex items-center gap-2.5 rounded-lg py-1 pl-1 pr-2 text-left transition-colors hover:bg-accent">
                            <Avatar className="h-8 w-8">
                                <AvatarFallback className="bg-primary/10 text-sm font-semibold text-primary">
                                    {user.name.charAt(0).toUpperCase()}
                                </AvatarFallback>
                            </Avatar>
                            <div className="hidden leading-tight sm:block">
                                <div className="text-sm font-semibold">{user.name}</div>
                                {role && <div className="text-xs text-muted-foreground">{role}</div>}
                            </div>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuLabel className="font-normal">
                                <div className="text-sm font-semibold">{user.name}</div>
                                <div className="truncate text-xs text-muted-foreground">{user.email}</div>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild>
                                <Link href={route('profile.edit')}>Profile</Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => router.post(route('logout'))}>
                                Log Out
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </header>
    );
}
