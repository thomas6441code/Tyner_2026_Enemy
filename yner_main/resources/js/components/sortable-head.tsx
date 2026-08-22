import { ChevronDown, ChevronUp, ChevronsUpDown } from 'lucide-react';

import { TableHead } from '@/components/ui/table';
import { cn } from '@/lib/utils';

import type { SortDirection } from '@/components/table-toolbar';

interface SortableHeadProps {
    /** Sort key sent to the server; must be one of the controller's whitelisted keys. */
    column: string;
    label: string;
    sort?: string | null;
    direction?: SortDirection | null;
    onSort: (column: string) => void;
    className?: string;
}

/**
 * A table header that sorts on click. The arrow shows the active direction; inactive columns
 * keep a dimmed double chevron so it is visible that they are sortable at all.
 */
export function SortableHead({ column, label, sort, direction, onSort, className }: SortableHeadProps) {
    const active = sort === column;
    const Icon = !active ? ChevronsUpDown : direction === 'desc' ? ChevronDown : ChevronUp;

    return (
        <TableHead className={className} aria-sort={active ? (direction === 'desc' ? 'descending' : 'ascending') : 'none'}>
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn(
                    'inline-flex items-center gap-1 rounded transition-colors hover:text-foreground',
                    active && 'text-foreground',
                )}
            >
                {label}
                <Icon className={cn('h-3.5 w-3.5', active ? 'opacity-100' : 'opacity-40')} />
            </button>
        </TableHead>
    );
}
