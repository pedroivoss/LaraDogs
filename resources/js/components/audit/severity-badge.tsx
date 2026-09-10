import { cn } from '@/lib/utils';
import type { Severity } from '@/types/audit';

/**
 * Severity is presented with a distinct color PER LEVEL plus its own text
 * label — never color alone (an unlabeled colored dot would fail for
 * colorblind users and doesn't scale past ~4 categories anyway).
 */
const SEVERITY_STYLES: Record<Severity, string> = {
    critical: 'border-transparent bg-red-600 text-white dark:bg-red-500',
    high: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    medium: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    low: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    info: 'border-border bg-muted text-muted-foreground',
    unknown: 'border-border bg-muted text-muted-foreground',
};

export function SeverityBadge({
    severity,
    className,
}: {
    severity: Severity;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex w-fit shrink-0 items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-semibold tracking-wide whitespace-nowrap uppercase',
                SEVERITY_STYLES[severity],
                className,
            )}
        >
            {severity}
        </span>
    );
}
