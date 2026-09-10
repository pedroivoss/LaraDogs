import { Head, Link } from '@inertiajs/react';
import { ArrowRight, ShieldAlert, Terminal } from 'lucide-react';
import { AnalyzerStatusBadge } from '@/components/audit/analyzer-status-badge';
import { CoverageBadge } from '@/components/audit/coverage-badge';
import { EmptyState } from '@/components/audit/empty-state';
import { FindingStatusBadge } from '@/components/audit/finding-status-badge';
import { SeverityBadge } from '@/components/audit/severity-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { dashboard } from '@/routes';
import {
    findings as projectFindings,
    index as projectsIndex,
    scans as projectScans,
    show as projectShow,
} from '@/routes/projects';
import { show as findingShow } from '@/routes/findings';
import type {
    AnalyzerExecutionSummary,
    FindingSummary,
    ScanSummary,
} from '@/types/audit';

type ProjectProfile = {
    backend?: {
        laravel?: {
            status: string;
            installed_version: string | null;
            constraint: string | null;
        };
        php?: {
            status: string;
            installed_version: string | null;
            constraint: string | null;
        };
    };
};

type Summary = {
    total_findings: number;
    open_findings: number;
    open_findings_by_severity: Record<string, number>;
    open_findings_by_category: Record<string, number>;
    last_scan_analyzer_statuses: Record<string, string>;
    last_scan: ScanSummary | null;
};

export default function ProjectShow({
    project,
    profile,
    summary,
    analyzer_executions,
    recent_scans,
    recent_findings,
    audit_command,
}: {
    project: { id: string; name: string; path: string };
    profile: ProjectProfile | null;
    summary: Summary;
    analyzer_executions: AnalyzerExecutionSummary[];
    recent_scans: ScanSummary[];
    recent_findings: FindingSummary[];
    audit_command: string;
}) {
    const laravelVersion =
        profile?.backend?.laravel?.installed_version ??
        profile?.backend?.laravel?.constraint;
    const phpVersion =
        profile?.backend?.php?.installed_version ??
        profile?.backend?.php?.constraint;

    return (
        <>
            <Head title={project.name} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold">{project.name}</h1>
                    <p className="text-muted-foreground font-mono text-xs">
                        {project.id}
                    </p>
                    <p className="text-muted-foreground font-mono text-xs break-all">
                        {project.path}
                    </p>
                    {(laravelVersion || phpVersion) && (
                        <p className="text-muted-foreground text-xs">
                            {laravelVersion && <>Laravel {laravelVersion}</>}
                            {laravelVersion && phpVersion && ' · '}
                            {phpVersion && <>PHP {phpVersion}</>}
                        </p>
                    )}
                </div>

                <Card>
                    <CardHeader className="flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-sm">
                            Run a new audit
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-muted-foreground mb-2 text-sm">
                            Dashboard-triggered audits are not available yet
                            (see the docs for why) — run:
                        </p>
                        <div className="bg-muted flex items-center gap-2 rounded-md px-3 py-2 font-mono text-xs">
                            <Terminal
                                className="text-muted-foreground size-3.5 shrink-0"
                                aria-hidden
                            />
                            {audit_command}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <SummaryStat
                        label="Open findings"
                        value={summary.open_findings}
                    />
                    <SummaryStat
                        label="Critical"
                        value={summary.open_findings_by_severity.critical ?? 0}
                        tone="critical"
                    />
                    <SummaryStat
                        label="High"
                        value={summary.open_findings_by_severity.high ?? 0}
                        tone="high"
                    />
                    <SummaryStat
                        label="Medium"
                        value={summary.open_findings_by_severity.medium ?? 0}
                    />
                    <SummaryStat
                        label="Low"
                        value={summary.open_findings_by_severity.low ?? 0}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">
                            Analyzer status (latest scan)
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {analyzer_executions.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                This project has not been scanned yet.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {analyzer_executions.map((execution) => (
                                    <div
                                        key={execution.analyzer_id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div>
                                            <p className="text-sm font-medium">
                                                {execution.analyzer_name}
                                            </p>
                                            {execution.summary && (
                                                <p className="text-muted-foreground text-xs">
                                                    {execution.summary}
                                                </p>
                                            )}
                                            {execution.note && (
                                                <p className="text-muted-foreground text-xs italic">
                                                    {execution.note}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {execution.duration_ms !== null && (
                                                <span className="text-muted-foreground text-xs">
                                                    {execution.duration_ms}ms
                                                </span>
                                            )}
                                            {execution.coverage && (
                                                <CoverageBadge
                                                    mode={
                                                        execution.coverage.mode
                                                    }
                                                />
                                            )}
                                            <AnalyzerStatusBadge
                                                status={execution.status}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-sm">
                            Current findings
                        </CardTitle>
                        <Link
                            href={projectFindings(project.id)}
                            className="text-primary flex items-center gap-1 text-xs hover:underline"
                        >
                            View all <ArrowRight className="size-3" />
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {recent_findings.length === 0 ? (
                            <EmptyState
                                icon={ShieldAlert}
                                title="No current findings"
                                description="No current findings were reported by the analyzers that completed with sufficient coverage. This is not the same as a guarantee the project is secure — see analyzer status above for coverage details."
                            />
                        ) : (
                            <div className="divide-y">
                                {recent_findings.map((finding) => (
                                    <Link
                                        key={finding.id}
                                        href={findingShow(finding.id)}
                                        className="hover:bg-muted/50 -mx-2 flex flex-wrap items-center justify-between gap-2 rounded-md px-2 py-3 first:pt-2 last:pb-2"
                                    >
                                        <div className="flex items-center gap-2">
                                            <SeverityBadge
                                                severity={finding.severity}
                                            />
                                            <span className="text-sm">
                                                {finding.title}
                                            </span>
                                        </div>
                                        <FindingStatusBadge
                                            status={finding.status}
                                        />
                                    </Link>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-sm">Recent scans</CardTitle>
                        <Link
                            href={projectScans(project.id)}
                            className="text-primary flex items-center gap-1 text-xs hover:underline"
                        >
                            View all <ArrowRight className="size-3" />
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {recent_scans.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No scans have been recorded yet.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {recent_scans.map((scan) => (
                                    <div
                                        key={scan.id}
                                        className="flex items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                    >
                                        <span className="text-sm">
                                            {new Date(
                                                scan.started_at,
                                            ).toLocaleString()}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {scan.status}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
                <Separator />
            </div>
        </>
    );
}

function SummaryStat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'critical' | 'high';
}) {
    const toneClass =
        tone === 'critical'
            ? 'text-red-600 dark:text-red-400'
            : tone === 'high'
              ? 'text-amber-600 dark:text-amber-400'
              : '';

    return (
        <Card>
            <CardContent className="pt-6">
                <p className={`text-2xl font-semibold ${toneClass}`}>{value}</p>
                <p className="text-muted-foreground text-xs">{label}</p>
            </CardContent>
        </Card>
    );
}

ProjectShow.layout = (props: { project: { id: string; name: string } }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Projects', href: projectsIndex() },
        { title: props.project.name, href: projectShow(props.project.id) },
    ],
});
