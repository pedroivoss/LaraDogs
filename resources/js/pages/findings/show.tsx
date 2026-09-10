import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CodeSnippet } from '@/components/audit/code-snippet';
import { ConfidenceBadge } from '@/components/audit/confidence-badge';
import { FindingStatusBadge } from '@/components/audit/finding-status-badge';
import { SeverityBadge } from '@/components/audit/severity-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { updateStatus } from '@/actions/App/Http/Controllers/FindingsController';
import { dashboard } from '@/routes';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import {
    FINDING_STATUSES_REQUIRING_REASON,
    type FindingStatus,
} from '@/types/audit';
import type { AnalyzerCategory, Confidence, Severity } from '@/types/audit';

type FindingDetail = {
    id: string;
    rule_id: string;
    analyzer_id: string;
    category: AnalyzerCategory;
    severity: Severity;
    confidence: Confidence;
    status: FindingStatus;
    status_reason: string | null;
    title: string;
    description: string | null;
    impact: string | null;
    recommendation: string | null;
    cwe: string | null;
    cve: string | null;
    references: string[] | null;
    first_seen_at: string;
    last_seen_at: string;
    project: { id: string; name: string };
};

type Occurrence = {
    file_path: string | null;
    line_start: number | null;
    line_end: number | null;
    code_snippet: string | null;
    context_code: string | null;
    rule_version: string | null;
    analyzer_version: string | null;
    observed_at: string;
    scan: { id: string; status: string; started_at: string };
};

type HistoryEntry = {
    previous_status: FindingStatus | null;
    new_status: FindingStatus;
    reason: string | null;
    actor_type: string;
    actor_identifier: string | null;
    created_at: string;
};

const STATUS_OPTIONS: FindingStatus[] = [
    'open',
    'confirmed',
    'resolved',
    'accepted_risk',
    'false_positive',
    'ignored',
];

export default function FindingShow({
    finding,
    occurrences,
    status_history,
}: {
    finding: FindingDetail;
    occurrences: Occurrence[];
    status_history: HistoryEntry[];
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const form = useForm<{ status: FindingStatus | ''; reason: string }>({
        status: '',
        reason: '',
    });

    const reasonRequired = FINDING_STATUSES_REQUIRING_REASON.includes(
        form.data.status as FindingStatus,
    );

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.patch(updateStatus(finding.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(false);
                form.reset();
            },
        });
    }

    return (
        <>
            <Head title={finding.title} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <div className="flex items-center gap-2">
                            <SeverityBadge severity={finding.severity} />
                            <ConfidenceBadge confidence={finding.confidence} />
                            <FindingStatusBadge status={finding.status} />
                        </div>
                        <h1 className="text-xl font-semibold">
                            {finding.title}
                        </h1>
                        <p className="text-muted-foreground font-mono text-xs break-all">
                            {finding.rule_id} · {finding.analyzer_id} ·{' '}
                            {finding.category}
                        </p>
                        {finding.status_reason && (
                            <p className="text-muted-foreground text-sm italic">
                                "{finding.status_reason}"
                            </p>
                        )}
                    </div>

                    <Dialog
                        open={dialogOpen}
                        onOpenChange={(open) => {
                            setDialogOpen(open);
                            if (!open) form.reset();
                        }}
                    >
                        <DialogTrigger asChild>
                            <Button variant="outline">Change status</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={submit}>
                                <DialogHeader>
                                    <DialogTitle>
                                        Change finding status
                                    </DialogTitle>
                                    <DialogDescription>
                                        This is recorded in the finding's status
                                        history and cannot be undone silently.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-4 py-4">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="status">
                                            New status
                                        </Label>
                                        <Select
                                            value={form.data.status}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'status',
                                                    value as FindingStatus,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="status"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Select a status" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {STATUS_OPTIONS.map(
                                                    (status) => (
                                                        <SelectItem
                                                            key={status}
                                                            value={status}
                                                        >
                                                            {status}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.status && (
                                            <p className="text-destructive text-sm">
                                                {form.errors.status}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label htmlFor="reason">
                                            Reason{' '}
                                            {reasonRequired && (
                                                <span className="text-destructive">
                                                    (required for this status)
                                                </span>
                                            )}
                                        </Label>
                                        <Textarea
                                            id="reason"
                                            value={form.data.reason}
                                            onChange={(event) =>
                                                form.setData(
                                                    'reason',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder={
                                                reasonRequired
                                                    ? 'Explain why this finding is being suppressed…'
                                                    : 'Optional'
                                            }
                                            required={reasonRequired}
                                        />
                                        {form.errors.reason && (
                                            <p className="text-destructive text-sm">
                                                {form.errors.reason}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="submit"
                                        disabled={
                                            form.processing ||
                                            form.data.status === ''
                                        }
                                    >
                                        {form.processing
                                            ? 'Saving…'
                                            : 'Save status'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        {finding.description && (
                            <Section title="Description">
                                {finding.description}
                            </Section>
                        )}
                        {finding.impact && (
                            <Section title="Impact — why LaraDogs reported this">
                                {finding.impact}
                            </Section>
                        )}
                        {finding.recommendation && (
                            <Section title="Recommendation">
                                {finding.recommendation}
                            </Section>
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Occurrences ({occurrences.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {occurrences.length === 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        No occurrences recorded.
                                    </p>
                                )}
                                {occurrences.map((occurrence, index) => (
                                    <div key={index} className="space-y-2">
                                        <p className="text-muted-foreground font-mono text-xs break-all">
                                            {occurrence.file_path ??
                                                '(no location)'}
                                            {occurrence.line_start !== null &&
                                                `:${occurrence.line_start}`}
                                            {' — observed '}
                                            {new Date(
                                                occurrence.observed_at,
                                            ).toLocaleString()}
                                        </p>
                                        <CodeSnippet
                                            content={occurrence.code_snippet}
                                            lineStart={occurrence.line_start}
                                        />
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Status history
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {status_history.length === 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        No history recorded.
                                    </p>
                                )}
                                {status_history.map((entry, index) => (
                                    <div
                                        key={index}
                                        className="flex flex-col gap-1 border-l-2 pl-3 text-sm"
                                    >
                                        <p>
                                            {entry.previous_status ? (
                                                <>
                                                    <FindingStatusBadge
                                                        status={
                                                            entry.previous_status
                                                        }
                                                    />{' '}
                                                    →{' '}
                                                </>
                                            ) : null}
                                            <FindingStatusBadge
                                                status={entry.new_status}
                                            />
                                        </p>
                                        {entry.reason && (
                                            <p className="text-muted-foreground italic">
                                                "{entry.reason}"
                                            </p>
                                        )}
                                        <p className="text-muted-foreground text-xs">
                                            {entry.actor_type}
                                            {entry.actor_identifier &&
                                                ` (${entry.actor_identifier})`}{' '}
                                            ·{' '}
                                            {new Date(
                                                entry.created_at,
                                            ).toLocaleString()}
                                        </p>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <DetailRow label="Project">
                                    <Link
                                        href={projectShow(finding.project.id)}
                                        className="hover:underline"
                                    >
                                        {finding.project.name}
                                    </Link>
                                </DetailRow>
                                {finding.cwe && (
                                    <DetailRow label="CWE">
                                        {finding.cwe}
                                    </DetailRow>
                                )}
                                {finding.cve && (
                                    <DetailRow label="CVE">
                                        {finding.cve}
                                    </DetailRow>
                                )}
                                <DetailRow label="First seen">
                                    {new Date(
                                        finding.first_seen_at,
                                    ).toLocaleString()}
                                </DetailRow>
                                <DetailRow label="Last seen">
                                    {new Date(
                                        finding.last_seen_at,
                                    ).toLocaleString()}
                                </DetailRow>
                            </CardContent>
                        </Card>

                        {finding.references &&
                            finding.references.length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-sm">
                                            References
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-1">
                                        {finding.references.map((reference) => (
                                            <a
                                                key={reference}
                                                href={reference}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary block truncate text-sm hover:underline"
                                            >
                                                {reference}
                                            </a>
                                        ))}
                                    </CardContent>
                                </Card>
                            )}
                    </div>
                </div>
            </div>
        </>
    );
}

function Section({ title, children }: { title: string; children: string }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-sm whitespace-pre-wrap">{children}</p>
            </CardContent>
        </Card>
    );
}

function DetailRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-2">
            <span className="text-muted-foreground">{label}</span>
            <span>{children}</span>
        </div>
    );
}

FindingShow.layout = (props: {
    finding: { title: string; project: { id: string; name: string } };
}) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Projects', href: projectsIndex() },
        {
            title: props.finding.project.name,
            href: projectShow(props.finding.project.id),
        },
        { title: props.finding.title, href: '#' },
    ],
});
