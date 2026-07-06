import { useState } from 'react';

export function usePagination<T>(items: T[], initialPageSize = 10) {
    const [pageSize, setPageSize] = useState(initialPageSize);
    const [page, setPage] = useState(1);

    const pageCount = Math.max(Math.ceil(items.length / pageSize), 1);
    const currentPage = Math.min(page, pageCount);
    const paginated = items.slice((currentPage - 1) * pageSize, currentPage * pageSize);

    function changePageSize(size: number) {
        setPageSize(size);
        setPage(1);
    }

    function reset() {
        setPage(1);
    }

    return {
        page: currentPage,
        pageSize,
        pageCount,
        paginated,
        total: items.length,
        setPage,
        setPageSize: changePageSize,
        reset,
    };
}
