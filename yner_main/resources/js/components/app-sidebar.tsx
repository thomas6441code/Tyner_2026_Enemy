import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    Brain,
    CalendarCheck,
    CalendarClock,
    Clock,
    FileText,
    Fingerprint,
    LayoutDashboard,
    type LucideIcon,
    MailCheck,
    MapPin,
    MapPinned,
    Moon,
    RefreshCw,
    ScanLine,
    Settings,
    Smartphone,
    Sun,
    UserPlus,
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
                    // The employee's own check-in page — shown to anyone with a linked active
                    // employee record, regardless of role.
                    href: route('check-in.show'),
                    label: 'Check In',
                    icon: MapPinned,
                    active: route().current('check-in.*'),
                    show: can.checkIn,
                },
                {
                    href: route('permission-requests.index'),
                    label: 'Permissions',
                    icon: CalendarClock,
                    active: route().current('permission-requests.*'),
                    show: can.viewPermissionRequests,
                },
                {
                    href: route('employees.index'),
                    label: 'Employees',
                    icon: Users,
                    active: route().current('employees.*'),
                    show: can.viewEmployees,
                },
                 {
                    href: route('reports.index'),
                    label: 'Reports',
                    icon: BarChart3,
                    active: route().current('reports.*'),
                    show: can.viewReports,
                },
                {
                    href: route('ai-insights.index'),
                    label: 'AI Insights',
                    icon: Brain,
                    active: route().current('ai-insights.*'),
                    show: can.viewAiInsights,
                },
                {
                    href: route('report-summaries.index'),
                    label: 'AI Summaries',
                    icon: FileText,
                    active: route().current('report-summaries.*'),
                    show: can.viewReportSummaries,
                }
            ],
        },
        {
            label: 'Organization',
            items: [
                {
                    href: route('work-schedules.index'),
                    label: 'Schedules',
                    icon: Clock,
                    active: route().current('work-schedules.*'),
                    show: can.viewWorkSchedules,
                },
                {
                    href: route('departments.index'),
                    label: 'Departments',
                    icon: Building2,
                    active: route().current('departments.*'),
                    show: can.viewDepartments,
                },
                {
                    href: route('work-locations.index'),
                    label: 'Work Locations',
                    icon: MapPin,
                    active: route().current('work-locations.*'),
                    show: can.viewWorkLocations,
                }
            ],
        },
        {
            label: 'Access',
            items: [
                {
                    href: route('registration-requests.index'),
                    label: 'Registration Requests',
                    icon: UserPlus,
                    active: route().current('registration-requests.*'),
                    show: can.viewRegistrationRequests,
                },
                {
                    href: route('account-invitations.index'),
                    label: 'Invitations',
                    icon: MailCheck,
                    active: route().current('account-invitations.*'),
                    show: can.viewAccountInvitations,
                },
            ],
        },
        {
            label: 'Devices',
            items: [
                {
                    // Always visible: every user manages their own linked phone here, unlike
                    // the biometric terminals below, which are Admin-only infrastructure.
                    // Singular on purpose — an account is linked to exactly one device.
                    href: route('devices.index'),
                    label: 'My Device',
                    icon: Smartphone,
                    active: route().current('devices.*'),
                    show: true,
                },
                {
                    href: route('device-reset-requests.index'),
                    label: 'Device Resets',
                    icon: RefreshCw,
                    active: route().current('device-reset-requests.*'),
                    show: can.reviewDeviceResets,
                },
                {
                    href: route('mobile-check-ins.index'),
                    label: 'Mobile Check-Ins',
                    icon: MapPinned,
                    active: route().current('mobile-check-ins.*'),
                    show: can.viewMobileCheckIns,
                },
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
        {
            label: 'System',
            items: [
                {
                    href: route('ai-settings.edit'),
                    label: 'AI Settings',
                    icon: Settings,
                    active: route().current('ai-settings.*'),
                    show: can.manageAiSettings,
                },
            ],
        },
    ];

    return (
        <>
            <div className="flex h-20 items-center px-5 pl-16 bottom-0 border-b border-border">
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
