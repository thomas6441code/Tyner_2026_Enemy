import { Link, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';

import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

export function AppSidebar() {
    const { can } = usePage<SharedData>().props;

    const items = [
        { href: route('dashboard'), label: 'Dashboard', active: route().current('dashboard'), show: true },
        {
            href: route('employees.index'),
            label: 'Employees',
            active: route().current('employees.*'),
            show: can.viewEmployees,
        },
        {
            href: route('departments.index'),
            label: 'Departments',
            active: route().current('departments.*'),
            show: can.viewDepartments,
        },
        {
            href: route('work-schedules.index'),
            label: 'Work Schedules',
            active: route().current('work-schedules.*'),
            show: can.viewWorkSchedules,
        },
        {
            href: route('biometric-devices.index'),
            label: 'Biometric Devices',
            active: route().current('biometric-devices.*'),
            show: can.viewBiometricDevices,
        },
        {
            href: route('device-enrollments.index'),
            label: 'Device Enrollments',
            active: route().current('device-enrollments.*'),
            show: can.viewDeviceEnrollments,
        },
    ];

    return (
        <nav className="w-56 shrink-0 border-r bg-background p-3">
            <ul className="flex flex-col gap-1">
                {items
                    .filter((item) => item.show)
                    .map((item) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                className={cn(
                                    'block rounded-md px-3 py-2 text-sm font-medium text-foreground/80 hover:bg-accent hover:text-accent-foreground',
                                    item.active && 'bg-accent font-semibold text-accent-foreground',
                                )}
                            >
                                {item.label}
                            </Link>
                        </li>
                    ))}
            </ul>
        </nav>
    );
}
