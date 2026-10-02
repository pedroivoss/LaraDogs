import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { RemediationPlan } from '@/types/audit';

/**
 * Remediation (Phase 12) — deterministic, GUIDANCE-ONLY.
 *
 * Every string below is rendered as plain React text (escaped): no raw HTML,
 * no Markdown, no HTML-injection API. There is deliberately no
 * edit/apply/fix button — LaraDogs advises, the developer changes the code.
 */

const SOURCE_LABEL: Record<RemediationPlan['source']['state'], string> = {
    same_revision: 'Same revision',
    changed_since_finding: 'Source changed',
    dirty: 'Uncommitted changes',
    unavailable: 'Source unavailable',
    not_versioned: 'Not versioned',
    unknown: 'Unknown',
};

const GATE_LABEL: Record<RemediationPlan['quality_gate']['impact'], string> = {
    blocking: 'Blocking the Quality Gate',
    non_blocking: 'Not blocking the Quality Gate',
    not_evaluated: 'Quality Gate not evaluated',
    undetermined: 'Quality Gate impact undetermined',
};

/** Defense in depth: the server already restricts references to https. */
function safeHttpsUrl(url: string): string | null {
    try {
        const parsed = new URL(url);

        return parsed.protocol === 'https:' && parsed.hostname !== ''
            ? parsed.toString()
            : null;
    } catch {
        return null;
    }
}

function ValidationLine({
    item,
}: {
    item: RemediationPlan['validation'][number];
}) {
    return (
        <li className="space-y-0.5">
            <span>{item.text}</span>
            {item.type === 'laradogs_command' && (
                <code className="bg-muted mt-0.5 block rounded px-1.5 py-0.5 text-xs break-all">
                    php artisan {item.command}{' '}
                    {Object.values(item.arguments).join(' ')}
                </code>
            )}
            {item.type === 'laradogs_tool' && (
                <code className="bg-muted mt-0.5 block rounded px-1.5 py-0.5 text-xs break-all">
                    MCP: {item.name}
                </code>
            )}
        </li>
    );
}

export function RemediationSection({ plan }: { plan: RemediationPlan }) {
    const { guidance, lifecycle } = plan;

    return (
        <Card data-testid="remediation-section">
            <CardHeader>
                <div className="flex flex-wrap items-center gap-2">
                    <CardTitle className="text-sm">Remediation</CardTitle>
                    <Badge variant="outline">Guidance only</Badge>
                    <Badge variant="secondary">
                        {SOURCE_LABEL[plan.source.state]}
                    </Badge>
                    <Badge
                        variant={
                            plan.quality_gate.impact === 'blocking'
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {GATE_LABEL[plan.quality_gate.impact]}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-5 text-sm">
                {!lifecycle.actionable && (
                    <p
                        className="bg-muted rounded-md px-3 py-2"
                        data-testid="remediation-lifecycle-note"
                    >
                        {lifecycle.note}
                    </p>
                )}

                {plan.warnings.length > 0 && (
                    <ul
                        className="space-y-1.5"
                        aria-label="Remediation warnings"
                    >
                        {plan.warnings.map((warning) => (
                            <li
                                key={warning.code}
                                className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-amber-900 dark:text-amber-200"
                            >
                                {warning.message}
                            </li>
                        ))}
                    </ul>
                )}

                {plan.guidance_available && (
                    <div className="space-y-1.5">
                        <h3 className="font-medium">Recommended action</h3>
                        <p className="break-words whitespace-pre-wrap">
                            {guidance.recommended_action ?? guidance.summary}
                        </p>
                    </div>
                )}

                {guidance.steps.length > 0 && (
                    <div className="space-y-1.5">
                        <h3 className="font-medium">Steps</h3>
                        <ol className="list-decimal space-y-1 pl-5">
                            {guidance.steps.map((step) => (
                                <li key={step.order} className="break-words">
                                    {step.text}
                                </li>
                            ))}
                        </ol>
                    </div>
                )}

                {guidance.limitations.length > 0 && (
                    <ul className="text-muted-foreground list-disc space-y-1 pl-5">
                        {guidance.limitations.map((limitation) => (
                            <li key={limitation} className="break-words">
                                {limitation}
                            </li>
                        ))}
                    </ul>
                )}

                {plan.dependency && (
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                        <dt className="text-muted-foreground">Package</dt>
                        <dd className="break-all">
                            {plan.dependency.package ?? 'unknown'} (
                            {plan.dependency.ecosystem})
                        </dd>
                        <dt className="text-muted-foreground">Affected</dt>
                        <dd className="break-all">
                            {plan.dependency.affected_versions ??
                                'not reported'}
                        </dd>
                        <dt className="text-muted-foreground">Fixed version</dt>
                        <dd>
                            {plan.dependency.fixed_version ??
                                (plan.dependency.fix_available === false
                                    ? 'none available yet'
                                    : 'not reported by the advisory source')}
                            {plan.dependency.fix_is_semver_major &&
                                ' (semver-major)'}
                        </dd>
                    </dl>
                )}

                <div className="space-y-1.5">
                    <h3 className="font-medium">Validation</h3>
                    <ul className="list-disc space-y-1.5 pl-5">
                        {plan.validation.map((item, index) => (
                            <ValidationLine key={index} item={item} />
                        ))}
                    </ul>
                </div>

                {plan.references.length > 0 && (
                    <div className="space-y-1.5">
                        <h3 className="font-medium">References</h3>
                        <ul className="space-y-1">
                            {plan.references.map(({ url }) => {
                                const safe = safeHttpsUrl(url);

                                return (
                                    <li key={url} className="break-all">
                                        {safe ? (
                                            <a
                                                href={safe}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-primary underline underline-offset-4 hover:no-underline"
                                            >
                                                {safe}
                                            </a>
                                        ) : (
                                            <span>{url}</span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                <p className="text-muted-foreground text-xs">
                    LaraDogs advises only — it never edits your code, runs your
                    tests or applies fixes. Guidance reflects current LaraDogs
                    advice for the stored finding facts.
                </p>
            </CardContent>
        </Card>
    );
}
