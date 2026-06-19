import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export function Pagination({ links }: { links: PaginationLink[] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {links.map((link, index) => (
                <Link
                    key={index}
                    href={link.url ?? '#'}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                    preserveScroll
                    className={cn(
                        'rounded-md border px-3 py-1 text-sm',
                        link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent',
                        !link.url && 'pointer-events-none opacity-50',
                    )}
                />
            ))}
        </div>
    );
}
