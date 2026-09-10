import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="border-border flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed px-6 py-16 text-center">
            <Icon className="text-muted-foreground size-8" aria-hidden />
            <div className="space-y-1">
                <p className="text-sm font-medium">{title}</p>
                <p className="text-muted-foreground max-w-md text-sm">
                    {description}
                </p>
            </div>
            {action}
        </div>
    );
}
