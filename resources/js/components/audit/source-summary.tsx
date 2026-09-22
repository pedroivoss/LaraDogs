import { Link } from '@inertiajs/react';
import { GitBranch, GitCommitHorizontal, TriangleAlert } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as scanShow } from '@/routes/projects/scans';
import type { SourceInfo, SourceOverview } from '@/types/audit';

function treeLabel(source: SourceInfo): string {
    if (source.dirty === null) {
        return 'Unknown';
    }

    return source.dirty ? 'Dirty' : 'Clean';
}

function branchLabel(source: SourceInfo): string {
    if (source.detached) {
        return 'Detached HEAD';
    }

    return source.branch ?? '—';
}

function DirtyBadge({ source }: { source: SourceInfo }) {
    if (source.dirty === null) {
        return null;
    }

    return source.dirty ? (
        <Badge
            variant="outline"
            className="border-amber-500 text-amber-700 dark:text-amber-300"
        >
            Dirty
        </Badge>
    ) : (
        <Badge variant="outline">Clean</Badge>
    );
}

/**
 * Compact revision cell for Scan History: short SHA, branch and working-tree
 * flag. A scan whose source was never captured (it pre-dates Git integration)
 * renders a neutral dash — never an invented revision.
 */
export function SourceRevisionCell({
    source,
}: {
    source: SourceInfo | null | undefined;
}) {
    if (!source) {
        return (
            <span className="text-muted-foreground" title="Source not captured">
                —<span className="sr-only">Source not captured</span>
            </span>
        );
    }

    if (source.type !== 'git') {
        return (
            <span className="text-muted-foreground text-xs">
                {source.label}
            </span>
        );
    }

    return (
        <div
            className="flex min-w-32 flex-col gap-1"
            data-testid="scan-source-cell"
        >
            <span className="font-mono text-xs font-medium">
                {source.short_commit ?? 'no commits'}
            </span>
            <span className="text-muted-foreground flex items-center gap-1 text-xs">
                <GitBranch className="size-3" aria-hidden="true" />
                {branchLabel(source)}
            </span>
            <span className="flex flex-wrap gap-1">
                <DirtyBadge source={source} />
                {source.consistent === false && (
                    <Badge
                        variant="outline"
                        className="border-amber-500 text-amber-700 dark:text-amber-300"
                        title={source.integrity_message ?? undefined}
                    >
                        {source.integrity_changed
                            ? 'Changed during audit'
                            : 'Integrity unverified'}
                    </Badge>
                )}
            </span>
        </div>
    );
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-1.5 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-right break-words">{children}</dd>
        </div>
    );
}

function SourceRows({ source }: { source: SourceInfo }) {
    if (source.type === 'none') {
        return (
            <p className="text-muted-foreground text-sm">
                Not a Git repository.
            </p>
        );
    }

    if (source.type === 'bare') {
        return (
            <p className="text-muted-foreground text-sm">
                A bare repository has no working tree, so it cannot be audited
                as source.
            </p>
        );
    }

    if (source.type === 'unavailable') {
        return (
            <p className="text-muted-foreground text-sm">
                Git state unavailable
                {source.reason ? ` — ${source.reason}` : '.'}
            </p>
        );
    }

    return (
        <dl className="divide-y">
            <Row label="Revision">
                <span className="font-mono">
                    {source.short_commit ?? 'No commits yet'}
                </span>
            </Row>
            <Row label="Branch">{branchLabel(source)}</Row>
            <Row label="Working tree">{treeLabel(source)}</Row>
        </dl>
    );
}

/**
 * Project Detail: the CURRENT mounted source next to the LAST AUDITED source
 * (an immutable snapshot) — never one standing in for the other.
 */
export function SourceCard({
    projectId,
    source,
}: {
    projectId: string;
    source: SourceOverview | undefined;
}) {
    if (!source) {
        return null;
    }

    const audited = source.last_audited?.source ?? null;

    return (
        <Card data-testid="source-card">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm">
                    <GitCommitHorizontal
                        className="size-4"
                        aria-hidden="true"
                    />
                    Source
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {source.changed_since_last_audit === true && (
                    <div
                        role="status"
                        data-testid="source-changed-banner"
                        className="flex items-start gap-2 rounded-md border border-dashed border-amber-500 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        Source changed since last audit — the latest results
                        describe an earlier state of this repository.
                    </div>
                )}

                <div className="grid gap-6 sm:grid-cols-2">
                    <section
                        aria-labelledby="source-current"
                        data-testid="source-current"
                    >
                        <h3
                            id="source-current"
                            className="mb-1 text-xs font-medium tracking-wide uppercase"
                        >
                            Current source state
                        </h3>
                        <SourceRows source={source.current} />
                    </section>

                    <section
                        aria-labelledby="source-audited"
                        data-testid="source-last-audited"
                    >
                        <h3
                            id="source-audited"
                            className="mb-1 text-xs font-medium tracking-wide uppercase"
                        >
                            Last audited source state
                        </h3>
                        {source.last_audited === null ? (
                            <p className="text-muted-foreground text-sm">
                                No completed audit yet.
                            </p>
                        ) : audited === null ? (
                            <p className="text-muted-foreground text-sm">
                                Not captured — this audit pre-dates Git
                                integration.
                            </p>
                        ) : (
                            <>
                                <SourceRows source={audited} />
                                <Link
                                    href={scanShow({
                                        project: projectId,
                                        scan: source.last_audited.scan_id,
                                    })}
                                    className="text-muted-foreground mt-1 inline-block text-xs hover:underline"
                                >
                                    View scan
                                </Link>
                            </>
                        )}
                    </section>
                </div>

                {audited?.consistent === false && (
                    <p className="text-xs text-amber-700 dark:text-amber-300">
                        {audited.integrity_message ??
                            'Source integrity could not be verified.'}
                    </p>
                )}
                {audited?.type === 'git' &&
                    audited.dirty === true &&
                    audited.consistent !== false && (
                        <p className="text-muted-foreground text-xs">
                            The last audit ran on a dirty working tree: its
                            revision alone does not reproduce the audited
                            source.
                        </p>
                    )}
            </CardContent>
        </Card>
    );
}

/**
 * Truthful integrity notice: says the source "changed" ONLY for a demonstrated
 * mutation; otherwise it says integrity could not be proven/verified.
 */
function IntegrityNotice({ source }: { source: SourceInfo }) {
    if (source.consistent !== false) {
        return null;
    }

    return (
        <div
            role="status"
            data-testid="source-integrity-notice"
            className="flex items-start gap-2 rounded-md border border-dashed border-amber-500 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
            <TriangleAlert
                className="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <span>
                {source.integrity_message ??
                    'Source integrity could not be verified.'}{' '}
                Absent findings were not auto-resolved and quality gates cannot
                pass on absence.
            </span>
        </div>
    );
}

/**
 * Scan Detail: the full, immutable provenance recorded when the scan ran.
 */
export function SourceSnapshotCard({
    source,
}: {
    source: SourceInfo | null | undefined;
}) {
    return (
        <Card data-testid="scan-source">
            <CardHeader>
                <CardTitle className="text-sm">Source</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {!source ? (
                    <p className="text-muted-foreground text-sm">
                        Not captured — this scan pre-dates Git integration, so
                        its source revision is unknown.
                    </p>
                ) : source.type !== 'git' ? (
                    <>
                        <IntegrityNotice source={source} />
                        <SourceRows source={source} />
                    </>
                ) : (
                    <>
                        <IntegrityNotice source={source} />
                        <dl className="divide-y">
                            <Row label="Commit">
                                <span className="font-mono text-xs break-all">
                                    {source.commit ?? 'No commits yet'}
                                </span>
                            </Row>
                            <Row label="Branch">
                                {source.detached
                                    ? 'Detached HEAD'
                                    : (source.branch ?? '—')}
                            </Row>
                            <Row label="Working tree">{treeLabel(source)}</Row>
                            <Row label="Source integrity">
                                {source.consistent === true
                                    ? 'Verified'
                                    : source.consistent === false
                                      ? source.integrity_changed
                                          ? 'Changed during audit'
                                          : 'Not proven'
                                      : 'No Git claim'}
                            </Row>
                            <Row label="Commit time">
                                {source.commit_at
                                    ? new Date(
                                          source.commit_at,
                                      ).toLocaleString()
                                    : '—'}
                            </Row>
                            <Row label="Commit subject">
                                {source.commit_subject ?? '—'}
                            </Row>
                            <Row label="Origin">{source.remote ?? '—'}</Row>
                        </dl>
                        {source.dirty === true && (
                            <p className="text-muted-foreground text-xs">
                                Audited with uncommitted changes: the commit
                                alone does not reproduce the audited source.
                            </p>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
