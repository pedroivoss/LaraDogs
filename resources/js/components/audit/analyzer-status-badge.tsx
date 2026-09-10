import { cn } from '@/lib/utils';
import type { ExecutionStatus } from '@/types/audit';

const STATUS_LABEL: Record<ExecutionStatus, string> = {
    planned: 'Planned',
    passed: 'Passed',
    failed: 'Failed',
    timed_out: 'Timed Out',
    skipped: 'Skipped',
    not_applicable: 'Not Applicable',
    unavailable: 'Unavailable',
};

// Deliberately: only `passed` reads as "good." `failed`/`timed_out` are
// unambiguously bad. Everything else (skipped/not_applicable/unavailable)
// is neutral — NEVER presented as equivalent to a clean pass, since none
// of them mean the analyzer actually verified anything this run.
const STATUS_STYLES: Record<ExecutionStatus, string> = {
    planned: 'border-border bg-muted text-muted-foreground',
    passed: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    timed_out:
        'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    skipped: 'border-border bg-muted text-muted-foreground',
    not_applicable: 'border-border bg-muted text-muted-foreground',
    unavailable: 'border-border bg-muted text-muted-foreground',
};

export function AnalyzerStatusBadge({
    status,
    className,
}: {
    status: ExecutionStatus;
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
