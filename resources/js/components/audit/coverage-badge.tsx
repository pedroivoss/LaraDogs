import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { CoverageMode } from '@/types/audit';

const COVERAGE_LABEL: Record<CoverageMode, string> = {
    unknown: 'Coverage: Unknown',
    explicit: 'Coverage: Explicit',
    full: 'Coverage: Full',
};

const COVERAGE_EXPLANATION: Record<CoverageMode, string> = {
    unknown:
        'This analyzer did not declare which checks it actually verified. A finding it previously reported can NOT be auto-resolved just because this run looks clean.',
    explicit:
        'This analyzer declared the exact rule IDs it verified this run. Findings for those specific rules can safely auto-resolve if not reported again.',
    full: 'This analyzer declared it verified its entire rule domain this run. Any of its findings can safely auto-resolve if not reported again.',
};

const COVERAGE_STYLES: Record<CoverageMode, string> = {
    unknown: 'border-border bg-muted text-muted-foreground',
    explicit:
        'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    full: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
};

export function CoverageBadge({
    mode,
    className,
}: {
    mode: CoverageMode;
    className?: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={cn(
                        'inline-flex w-fit shrink-0 cursor-help items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                        COVERAGE_STYLES[mode],
                        className,
                    )}
                >
                    {COVERAGE_LABEL[mode]}
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-64">
                {COVERAGE_EXPLANATION[mode]}
            </TooltipContent>
        </Tooltip>
    );
}
