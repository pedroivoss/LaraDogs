import { CircleHelp, Check, Minus, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { GateOutcome } from '@/types/audit';

type GateState = GateOutcome | 'disabled' | 'not_evaluated';

const LABEL: Record<GateState, string> = {
    passed: 'Passed',
    failed: 'Failed',
    indeterminate: 'Indeterminate',
    disabled: 'Disabled',
    not_evaluated: 'Not evaluated',
};

// Never distinguished by colour alone: every state has its own icon AND its
// own text, and Indeterminate additionally has a dashed border (it is not a
// warning and not a pass — it means "cannot be asserted"). Disabled /
// Not evaluated are neutral and never look like a pass.
const STYLE: Record<GateState, string> = {
    passed: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    indeterminate:
        'border-dashed border-amber-500 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-300',
    disabled: 'border-border bg-muted text-muted-foreground',
    not_evaluated: 'border-border bg-muted text-muted-foreground',
};

const ICON: Record<GateState, typeof Check> = {
    passed: Check,
    failed: X,
    indeterminate: CircleHelp,
    disabled: Minus,
    not_evaluated: Minus,
};

export function GateOutcomeBadge({
    state,
    className,
}: {
    state: GateState;
    className?: string;
}) {
    const Icon = ICON[state];

    return (
        <span
            data-gate-state={state}
            className={cn(
                'inline-flex w-fit shrink-0 items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                STYLE[state],
                className,
            )}
        >
            <Icon className="size-3" aria-hidden="true" />
            {LABEL[state]}
        </span>
    );
}
