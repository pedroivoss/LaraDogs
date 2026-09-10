import { cn } from '@/lib/utils';
import type { Confidence } from '@/types/audit';

/**
 * Confidence deliberately uses a completely different visual language than
 * SeverityBadge (neutral outline, no red/amber/blue scale) — the two must
 * never look like the same kind of signal. "High confidence" is NOT "high
 * severity," and the UI must not imply otherwise.
 */
const CONFIDENCE_OPACITY: Record<Confidence, string> = {
    high: 'opacity-100',
    medium: 'opacity-75',
    low: 'opacity-50',
};

export function ConfidenceBadge({
    confidence,
    className,
}: {
    confidence: Confidence;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'border-border text-foreground inline-flex w-fit shrink-0 items-center gap-1 rounded-md border border-dashed px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                CONFIDENCE_OPACITY[confidence],
                className,
            )}
        >
            confidence: {confidence}
        </span>
    );
}
