import { router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { route } from 'ziggy-js';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export type SortDirection = 'asc' | 'desc';

export interface IndexFilters {
    search?: string | null;
    sort?: string | null;
    direction?: SortDirection | null;
}

/**
 * Drop empty values so the address bar only ever carries filters that are actually set —
 * a shareable URL, and a "Reset" that produces the bare index route again.
 */
export function cleanQuery(params: Record<string, unknown>): Record<string, string | number | boolean> {
    return Object.fromEntries(
        Object.entries(params).filter(
            ([, value]) => value !== null && value !== undefined && value !== '' && value !== false,
        ),
    ) as Record<string, string | number | boolean>;
}

/**
 * Re-request the index with a patched query string. `page` is always dropped: after changing
 * a filter or a sort, page 4 of the previous result set is meaningless and usually empty.
 */
export function visitIndex(routeName: string, params: Record<string, unknown>): void {
    router.get(route(routeName), cleanQuery({ ...params, page: undefined }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/**
 * The direction a header click should produce: flip when the column is already the active
 * sort, otherwise start ascending.
 */
export function nextDirection(column: string, sort?: string | null, direction?: SortDirection | null): SortDirection {
    return sort === column && direction === 'asc' ? 'desc' : 'asc';
}

interface SearchInputProps {
    value?: string | null;
    onSearch: (term: string) => void;
    placeholder?: string;
    className?: string;
    id?: string;
}

/**
 * Debounced search box. The visit fires 300ms after the last keystroke rather than on submit,
 * and the local state is reconciled against the server value so the Back button and a
 * cleared filter both land where the user expects.
 */
export function SearchInput({ value, onSearch, placeholder = 'Search…', className, id }: SearchInputProps) {
    const serverValue = value ?? '';
    const [term, setTerm] = useState(serverValue);

    useEffect(() => {
        setTerm(serverValue);
    }, [serverValue]);

    useEffect(() => {
        if (term === serverValue) {
            return;
        }

        const timer = setTimeout(() => onSearch(term), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <div className={cn('relative', className)}>
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                id={id}
                type="search"
                value={term}
                placeholder={placeholder}
                aria-label={placeholder}
                className="bg-muted/40 pl-9 pr-8 focus:bg-background"
                onChange={(e) => setTerm(e.target.value)}
            />
            {term !== '' && (
                <button
                    type="button"
                    aria-label="Clear search"
                    onClick={() => {
                        setTerm('');
                        onSearch('');
                    }}
                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <X className="h-3.5 w-3.5" />
                </button>
            )}
        </div>
    );
}

/**
 * The bar above a table: search on the left, filters/actions on the right.
 */
export function TableToolbar({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <div className={cn('flex flex-wrap items-center gap-3 border-b border-border p-4', className)}>{children}</div>
    );
}
