import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarCheck,
    Clock,
    Fingerprint,
    LayoutDashboard,
    type LucideIcon,
    Moon,
    ScanLine,
    Sun,
    Users,
    X,
} from 'lucide-react';
import { route } from 'ziggy-js';

import { Logo } from '@/components/logo';
import { useTheme } from '@/components/use-theme';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

interface NavItem {
    href: string;
    label: string;
    icon: LucideIcon;
    active: boolean;
    show: boolean;
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

function SidebarContent({ onNavigate }: { onNavigate?: () => void }) {
    const { can } = usePage<SharedData>().props;
    const { theme, setTheme } = useTheme();

    const groups: NavGroup[] = [
        {
            label: 'General',
            items: [
                {
                    href: route('dashboard'),
                    label: 'Dashboard',
                    icon: LayoutDashboard,
                    active: route().current('dashboard'),
                    show: true,
                },
            ],
        },
        {
            label: 'Activities',
            items: [
                {
                    href: route('attendance.index'),
                    label: 'Attendance',
                    icon: CalendarCheck,
                    active: route().current('attendance.*'),
                    show: true,
                },
                {
                    href: route('employees.index'),
                    label: 'Employees',
                    icon: Users,
                    active: route().current('employees.*'),
                    show: can.viewEmployees,
                },
            ],
        },
        {
            label: 'Organization',
            items: [
                {
                    href: route('departments.index'),
                    label: 'Departments',
                    icon: Building2,
                    active: route().current('departments.*'),
                    show: can.viewDepartments,
                },
                {
                    href: route('work-schedules.index'),
                    label: 'Work Schedules',
                    icon: Clock,
                    active: route().current('work-schedules.*'),
                    show: can.viewWorkSchedules,
                },
            ],
        },
        {
            label: 'Devices',
            items: [
                {
                    href: route('biometric-devices.index'),
                    label: 'Biometric Devices',
                    icon: Fingerprint,
                    active: route().current('biometric-devices.*'),
                    show: can.viewBiometricDevices,
                },
                {
                    href: route('device-enrollments.index'),
                    label: 'Device Enrollments',
                    icon: ScanLine,
                    active: route().current('device-enrollments.*'),
                    show: can.viewDeviceEnrollments,
                },
            ],
        },
    ];

    return (
        <>
            <div className="flex h-20 items-center px-5">
                <Logo className="ml-2 mt-2 text-3xl" />
            </div>

            <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-4">
                {groups
                    .map((group) => ({ ...group, items: group.items.filter((i) => i.show) }))
                    .filter((group) => group.items.length > 0)
                    .map((group) => (
                        <div key={group.label}>
                            <div className="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                {group.label}
                            </div>
                            <ul className="space-y-1">
                                {group.items.map((item) => {
                                    const Icon = item.icon;
                                    return (
                                        <li key={item.href}>
                                            <Link
                                                href={item.href}
                                                onClick={onNavigate}
                                                className={cn(
                                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-foreground/70 transition-colors hover:bg-accent hover:text-foreground',
                                                    item.active &&
                                                        'bg-primary/10 text-primary hover:bg-primary/10 hover:text-primary',
                                                )}
                                            >
                                                <Icon className="h-[18px] w-[18px]" />
                                                {item.label}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ))}
            </nav>

            <div className="p-3">
                <div className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1">
                    <button
                        type="button"
                        onClick={() => setTheme('light')}
                        className={cn(
                            'flex items-center justify-center gap-1.5 rounded-md py-1.5 text-xs font-medium text-muted-foreground transition-colors',
                            theme === 'light' && 'bg-background text-foreground shadow-soft',
                        )}
                    >
                        <Sun className="h-4 w-4" /> Light
                    </button>
                    <button
                        type="button"
                        onClick={() => setTheme('dark')}
                        className={cn(
                            'flex items-center justify-center gap-1.5 rounded-md py-1.5 text-xs font-medium text-muted-foreground transition-colors',
                            theme === 'dark' && 'bg-background text-foreground shadow-soft',
                        )}
                    >
                        <Moon className="h-4 w-4" /> Dark
                    </button>
                </div>
            </div>
        </>
    );
}

interface AppSidebarProps {
    mobileOpen: boolean;
    onClose: () => void;
}

export function AppSidebar({ mobileOpen, onClose }: AppSidebarProps) {
    return (
        <>
            <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col bg-background shadow-[1px_0_3px_0_rgb(16_24_40/0.04)] md:flex">
                <SidebarContent />
            </aside>

            <div
                className={cn('fixed inset-0 z-50 md:hidden', !mobileOpen && 'pointer-events-none')}
                aria-hidden={!mobileOpen}
            >
                <div
                    className={cn(
                        'absolute inset-0 bg-black/40 transition-opacity duration-300',
                        mobileOpen ? 'opacity-100' : 'opacity-0',
                    )}
                    onClick={onClose}
                />
                <aside
                    className={cn(
                        'absolute left-0 top-0 flex h-full w-64 flex-col bg-background shadow-xl transition-transform duration-300',
                        mobileOpen ? 'translate-x-0' : '-translate-x-full',
                    )}
                >
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close menu"
                        className="absolute right-3 top-4 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                        <X className="h-5 w-5" />
                    </button>
                    <SidebarContent onNavigate={onClose} />
                </aside>
            </div>
        </>
    );
}
