import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

const PAGE_SIZE_OPTIONS = [5,10, 20] as const;

interface TablePaginationProps {
    page: number;
    pageCount: number;
    pageSize: number;
    total: number;
    onPageChange: (page: number) => void;
    onPageSizeChange: (size: number) => void;
    className?: string;
}

export function TablePagination({
    page,
    pageCount,
    pageSize,
    total,
    onPageChange,
    onPageSizeChange,
    className,
}: TablePaginationProps) {
    return (
        <div className={cn('flex flex-wrap items-center justify-between gap-3', className)}>
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                Rows per page
                <Select value={String(pageSize)} onValueChange={(value) => onPageSizeChange(Number(value))}>
                    <SelectTrigger className="h-8 w-17.5">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {PAGE_SIZE_OPTIONS.map((size) => (
                            <SelectItem key={size} value={String(size)}>
                                {size}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="flex items-center gap-3 text-sm text-muted-foreground">
                <span>
                    {total === 0
                        ? '0 of 0'
                        : `${(page - 1) * pageSize + 1}–${Math.min(page * pageSize, total)} of ${total}`}
                </span>
                <div className="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon"
                        className="h-8 w-8"
                        disabled={page <= 1}
                        onClick={() => onPageChange(Math.max(page - 1, 1))}
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        className="h-8 w-8"
                        disabled={page >= pageCount}
                        onClick={() => onPageChange(Math.min(page + 1, pageCount))}
                    >
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
