import {
    Pagination,
    PaginationContent,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import type { Pagination as PaginationMeta } from '@/types/audit';

export function DataPagination({
    pagination,
    onPageChange,
}: {
    pagination: PaginationMeta;
    onPageChange: (page: number) => void;
}) {
    if (pagination.last_page <= 1) {
        return null;
    }

    const pages = Array.from(
        { length: pagination.last_page },
        (_, index) => index + 1,
    ).filter(
        (page) =>
            page === 1 ||
            page === pagination.last_page ||
            Math.abs(page - pagination.current_page) <= 1,
    );

    return (
        <div className="flex items-center justify-between gap-4">
            <p className="text-muted-foreground text-sm">
                Page {pagination.current_page} of {pagination.last_page} (
                {pagination.total} total)
            </p>
            <Pagination className="mx-0 w-auto justify-end">
                <PaginationContent>
                    <PaginationItem>
                        <PaginationPrevious
                            href="#"
                            aria-disabled={pagination.current_page <= 1}
                            className={
                                pagination.current_page <= 1
                                    ? 'pointer-events-none opacity-50'
                                    : ''
                            }
                            onClick={(event) => {
                                event.preventDefault();
                                if (pagination.current_page > 1)
                                    onPageChange(pagination.current_page - 1);
                            }}
                        />
                    </PaginationItem>
                    {pages.map((page, index) => (
                        <PaginationItem key={page}>
                            {index > 0 && pages[index - 1] !== page - 1 && (
                                <span className="text-muted-foreground px-1">
                                    …
                                </span>
                            )}
                            <PaginationLink
                                href="#"
                                isActive={page === pagination.current_page}
                                onClick={(event) => {
                                    event.preventDefault();
                                    onPageChange(page);
                                }}
                            >
                                {page}
                            </PaginationLink>
                        </PaginationItem>
                    ))}
                    <PaginationItem>
                        <PaginationNext
                            href="#"
                            aria-disabled={
                                pagination.current_page >= pagination.last_page
                            }
                            className={
                                pagination.current_page >= pagination.last_page
                                    ? 'pointer-events-none opacity-50'
                                    : ''
                            }
                            onClick={(event) => {
                                event.preventDefault();
                                if (
                                    pagination.current_page <
                                    pagination.last_page
                                )
                                    onPageChange(pagination.current_page + 1);
                            }}
                        />
                    </PaginationItem>
                </PaginationContent>
            </Pagination>
        </div>
    );
}
