import { cn } from '@/lib/utils';
import type { FindingStatus } from '@/types/audit';

const STATUS_LABEL: Record<FindingStatus, string> = {
    open: 'Open',
    confirmed: 'Confirmed',
    resolved: 'Resolved',
    accepted_risk: 'Accepted Risk',
    false_positive: 'False Positive',
    ignored: 'Ignored',
};

const STATUS_STYLES: Record<FindingStatus, string> = {
    open: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    confirmed: 'border-transparent bg-red-600 text-white dark:bg-red-500',
    resolved:
        'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    accepted_risk: 'border-border bg-muted text-muted-foreground',
    false_positive: 'border-border bg-muted text-muted-foreground',
    ignored: 'border-border bg-muted text-muted-foreground',
};

export function FindingStatusBadge({
    status,
    className,
}: {
    status: FindingStatus;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex w-fit shrink-0 items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap',
                STATUS_STYLES[status],
                className,
            )}
        >
            {STATUS_LABEL[status]}
        </span>
    );
}
