import { cn } from '@/lib/utils';

/**
 * Scan origin (Manual / Scheduled / CLI) is provenance — why a scan
 * happened — NOT a severity, status or confidence signal. Deliberately a
 * flat, neutral muted chip (no red/amber/green scale, no dashed outline)
 * so it can never be read as a success/failure level or mistaken for
 * ConfidenceBadge. The label comes from the server (`ScanOrigin::label()`),
 * never re-derived here, and never names the person who started the scan.
 */
export function OriginBadge({
    label,
    className,
}: {
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'bg-muted text-muted-foreground inline-flex w-fit shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                className,
            )}
        >
            {label}
        </span>
    );
}
