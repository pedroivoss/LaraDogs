import { Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { GateOutcomeBadge } from '@/components/audit/gate-outcome-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { update as updateQualityGate } from '@/routes/projects/quality-gate';
import { show as scanShow } from '@/routes/projects/scans';
import type { GateSummary } from '@/types/audit';

export type QualityGateProps = {
    enabled: boolean;
    revision: number;
    form: {
        max_open: Record<string, number | null>;
        no_new: { enabled: boolean; min_severity: string };
        analyzer_status: string[];
        coverage: Record<string, string>;
    };
    latest: (GateSummary & { scan_id: string }) | null;
    analyzers: { id: string; name: string }[];
};

const SEVERITIES: [string, string][] = [
    ['critical', 'Critical'],
    ['high', 'High'],
    ['medium', 'Medium'],
    ['low', 'Low'],
    ['info', 'Info'],
    ['unknown', 'Unknown severity'],
];

// Only Semgrep declares coverage today (composer-audit / npm-audit always
// report Unknown, and no analyzer reports Full) — a coverage requirement
// is only offered where it can ever be met.
const COVERAGE_CAPABLE = ['semgrep'];

type FormData = {
    enabled: boolean;
    max_open: Record<string, string>;
    no_new: { enabled: boolean; min_severity: string };
    analyzer_status: string[];
    coverage: Record<string, string>;
};

function initial(gate: QualityGateProps): FormData {
    return {
        enabled: gate.enabled,
        max_open: Object.fromEntries(
            SEVERITIES.map(([key]) => [
                key,
                gate.form.max_open[key] === null ||
                gate.form.max_open[key] === undefined
                    ? ''
                    : String(gate.form.max_open[key]),
            ]),
        ),
        no_new: { ...gate.form.no_new },
        analyzer_status: [...gate.form.analyzer_status],
        coverage: { ...gate.form.coverage },
    };
}

function StatusLine({
    gate,
    projectId,
    hasAudit,
}: {
    gate: QualityGateProps;
    projectId: string;
    hasAudit: boolean;
}) {
    if (!gate.enabled) {
        return (
            <div className="flex flex-wrap items-center gap-2">
                <GateOutcomeBadge state="disabled" />
                <span className="text-muted-foreground text-sm">
                    Quality Gate disabled — audits are not judged against a
                    policy.
                </span>
            </div>
        );
    }

    if (!gate.latest) {
        return (
            <div className="flex flex-wrap items-center gap-2">
                <GateOutcomeBadge state="not_evaluated" />
                <span className="text-muted-foreground text-sm">
                    {hasAudit
                        ? 'The latest audit has no gate result (it finished before the gate was enabled). The next audit will be evaluated.'
                        : 'No audit has run yet. The first audit will be evaluated.'}
                </span>
            </div>
        );
    }

    const latest = gate.latest;
    const detail =
        latest.outcome === 'failed'
            ? `${latest.rules_failed} policy violation${latest.rules_failed === 1 ? '' : 's'}`
            : latest.outcome === 'indeterminate'
              ? (latest.headline ?? 'Not enough trustworthy evidence')
              : `All ${latest.rules_total} rules satisfied`;

    return (
        <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
                <GateOutcomeBadge state={latest.outcome} />
                <span className="text-sm">{detail}</span>
            </div>
            <p className="text-muted-foreground text-xs">
                Policy revision {latest.policy_revision} ·{' '}
                <Link
                    className="underline"
                    href={scanShow({
                        project: projectId,
                        scan: latest.scan_id,
                    })}
                >
                    View rule results
                </Link>
            </p>
        </div>
    );
}

function PolicySummary({ gate }: { gate: QualityGateProps }) {
    const limits = SEVERITIES.filter(
        ([key]) => gate.form.max_open[key] !== null,
    ).map(([key, label]) => `${label} ≤ ${gate.form.max_open[key]}`);
    const rules: string[] = [];

    if (limits.length > 0) {
        rules.push(`Maximum open findings: ${limits.join(', ')}`);
    }

    if (gate.form.no_new.enabled) {
        rules.push(
            `No new findings at or above ${gate.form.no_new.min_severity}`,
        );
    }

    if (gate.form.analyzer_status.length > 0) {
        rules.push(
            `Analyzers must pass: ${gate.form.analyzer_status.join(', ')}`,
        );
    }

    const coverage = Object.entries(gate.form.coverage).map(
        ([id, level]) => `${id} ${level.replace('_', ' ')}`,
    );

    if (coverage.length > 0) {
        rules.push(`Coverage required: ${coverage.join(', ')}`);
    }

    return (
        <div className="space-y-1">
            <p className="text-muted-foreground text-xs">
                Only an Owner or Admin can change this policy
                {gate.revision > 0 ? ` (revision ${gate.revision})` : ''}.
            </p>
            {gate.enabled && rules.length > 0 ? (
                <ul className="list-inside list-disc text-sm">
                    {rules.map((rule) => (
                        <li key={rule}>{rule}</li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

export function QualityGateCard({
    projectId,
    gate,
    canManage,
    hasAudit,
}: {
    projectId: string;
    gate: QualityGateProps;
    canManage: boolean;
    hasAudit: boolean;
}) {
    const form = useForm<FormData>(initial(gate));
    const { setData, reset } = form;

    // A new revision (saved elsewhere / after a poll) resets the editor.
    useEffect(() => {
        setData(initial(gate));
    }, [gate.revision]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <Card data-testid="quality-gate-card">
            <CardHeader>
                <CardTitle className="text-sm">Quality Gate</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <StatusLine
                    gate={gate}
                    projectId={projectId}
                    hasAudit={hasAudit}
                />

                {!canManage ? (
                    <PolicySummary gate={gate} />
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(updateQualityGate(projectId).url, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="qg-enabled"
                                checked={form.data.enabled}
                                onCheckedChange={(checked) =>
                                    form.setData('enabled', checked === true)
                                }
                            />
                            <Label htmlFor="qg-enabled">
                                Enable Quality Gate
                            </Label>
                        </div>

                        <fieldset className="space-y-2">
                            <legend className="text-muted-foreground text-xs">
                                Maximum open findings (leave blank to not
                                enforce a severity; 0 means none allowed)
                            </legend>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {SEVERITIES.map(([key, label]) => (
                                    <div key={key} className="space-y-1">
                                        <Label
                                            htmlFor={`qg-max-${key}`}
                                            className="text-xs"
                                        >
                                            {label}
                                        </Label>
                                        <Input
                                            id={`qg-max-${key}`}
                                            type="number"
                                            min={0}
                                            inputMode="numeric"
                                            value={form.data.max_open[key]}
                                            onChange={(event) =>
                                                form.setData('max_open', {
                                                    ...form.data.max_open,
                                                    [key]: event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        </fieldset>

                        <div className="flex flex-wrap items-center gap-3">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="qg-no-new"
                                    checked={form.data.no_new.enabled}
                                    onCheckedChange={(checked) =>
                                        form.setData('no_new', {
                                            ...form.data.no_new,
                                            enabled: checked === true,
                                        })
                                    }
                                />
                                <Label htmlFor="qg-no-new">
                                    Fail on new findings at or above
                                </Label>
                            </div>
                            <Select
                                value={form.data.no_new.min_severity}
                                onValueChange={(value) =>
                                    form.setData('no_new', {
                                        ...form.data.no_new,
                                        min_severity: value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-32"
                                    aria-label="Minimum severity for new findings"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SEVERITIES.filter(
                                        ([key]) => key !== 'unknown',
                                    ).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <fieldset className="space-y-2">
                            <legend className="text-muted-foreground text-xs">
                                Analyzers that must complete and pass
                            </legend>
                            <div className="flex flex-wrap gap-4">
                                {gate.analyzers.map((analyzer) => (
                                    <div
                                        key={analyzer.id}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`qg-an-${analyzer.id}`}
                                            checked={form.data.analyzer_status.includes(
                                                analyzer.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'analyzer_status',
                                                    checked === true
                                                        ? [
                                                              ...form.data
                                                                  .analyzer_status,
                                                              analyzer.id,
                                                          ]
                                                        : form.data.analyzer_status.filter(
                                                              (id) =>
                                                                  id !==
                                                                  analyzer.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={`qg-an-${analyzer.id}`}
                                            className="text-sm"
                                        >
                                            {analyzer.name}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        </fieldset>

                        {gate.analyzers
                            .filter((a) => COVERAGE_CAPABLE.includes(a.id))
                            .map((analyzer) => (
                                <div
                                    key={analyzer.id}
                                    className="flex flex-wrap items-center gap-3"
                                >
                                    <Label className="text-sm">
                                        {analyzer.name} coverage
                                    </Label>
                                    <Select
                                        value={
                                            form.data.coverage[analyzer.id] ||
                                            'none'
                                        }
                                        onValueChange={(value) =>
                                            form.setData('coverage', {
                                                ...form.data.coverage,
                                                [analyzer.id]:
                                                    value === 'none'
                                                        ? ''
                                                        : value,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-48"
                                            aria-label={`${analyzer.name} coverage requirement`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                Not required
                                            </SelectItem>
                                            <SelectItem value="explicit_or_full">
                                                Explicit or full
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            ))}

                        {Object.keys(form.errors).length > 0 && (
                            <ul
                                role="alert"
                                className="text-destructive list-inside list-disc text-sm"
                            >
                                {Object.values(form.errors).map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        )}

                        <div className="flex items-center gap-3">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={form.processing}
                            >
                                Save Quality Gate
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => reset()}
                                disabled={!form.isDirty}
                            >
                                Reset
                            </Button>
                            {gate.revision > 0 && (
                                <span className="text-muted-foreground text-xs">
                                    Policy revision {gate.revision}
                                </span>
                            )}
                        </div>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}
