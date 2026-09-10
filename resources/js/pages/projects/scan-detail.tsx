import { Head, Link } from '@inertiajs/react';
import { AnalyzerStatusBadge } from '@/components/audit/analyzer-status-badge';
import { CoverageBadge } from '@/components/audit/coverage-badge';
import { SeverityBadge } from '@/components/audit/severity-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { show as findingShow } from '@/routes/findings';
import {
    index as projectsIndex,
    scans as projectScansRoute,
    show as projectShow,
} from '@/routes/projects';
import type {
    AnalyzerExecutionSummary,
    FindingSummary,
    ScanStatus,
} from '@/types/audit';

type ScanDetail = {
    id: string;
    status: ScanStatus;
    started_at: string;
    finished_at: string | null;
    duration_ms: number | null;
    laradogs_version: string | null;
    source_revision: string | null;
    project_profile: Record<string, unknown> | null;
    environment: Record<string, unknown> | null;
    findings_summary: {
        observed: number;
        auto_resolved: number;
        by_severity: Record<string, number>;
    } | null;
};

export default function ScanDetail({
    project,
    scan,
    analyzer_executions,
    observed_findings,
}: {
    project: { id: string; name: string };
    scan: ScanDetail;
    analyzer_executions: AnalyzerExecutionSummary[];
    observed_findings: FindingSummary[];
}) {
    return (
        <>
            <Head title={`Scan ${scan.id} — ${project.name}`} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold">Scan {scan.id}</h1>
                    <p className="text-muted-foreground text-sm">
                        This is an immutable historical record — it always
                        reflects what was actually observed at the time of this
                        scan, never the project&apos;s current state.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Status" value={scan.status} />
                    <Stat
                        label="Started"
                        value={new Date(scan.started_at).toLocaleString()}
                    />
                    <Stat
                        label="Finished"
                        value={
                            scan.finished_at
                                ? new Date(scan.finished_at).toLocaleString()
                                : '—'
                        }
                    />
                    <Stat
                        label="Duration"
                        value={
                            scan.duration_ms !== null
                                ? `${scan.duration_ms}ms`
                                : '—'
                        }
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">
                            Analyzer executions
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {analyzer_executions.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No analyzer executions recorded.
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
                                            {execution.diagnostics &&
                                                execution.diagnostics.length >
                                                    0 && (
                                                    <ul className="text-muted-foreground mt-1 list-inside list-disc text-xs">
                                                        {execution.diagnostics.map(
                                                            (
                                                                diagnostic,
                                                                index,
                                                            ) => (
                                                                <li key={index}>
                                                                    [
                                                                    {
                                                                        diagnostic.level
                                                                    }
                                                                    ]{' '}
                                                                    {
                                                                        diagnostic.message
                                                                    }
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
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
                    <CardHeader>
                        <CardTitle className="text-sm">
                            Findings observed in this scan (
                            {observed_findings.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {observed_findings.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No findings were observed in this scan.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {observed_findings.map((finding) => (
                                    <Link
                                        key={finding.id}
                                        href={findingShow(finding.id)}
                                        className="hover:bg-muted/50 -mx-2 flex items-center gap-2 rounded-md px-2 py-3"
                                    >
                                        <SeverityBadge
                                            severity={finding.severity}
                                        />
                                        <span className="text-sm">
                                            {finding.title}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {scan.project_profile !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Project profile snapshot (as observed at this
                                scan)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre className="bg-muted/50 overflow-x-auto rounded-md p-3 text-xs">
                                {JSON.stringify(scan.project_profile, null, 2)}
                            </pre>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <Card>
            <CardContent className="pt-6">
                <p className="text-lg font-semibold">{value}</p>
                <p className="text-muted-foreground text-xs">{label}</p>
            </CardContent>
        </Card>
    );
}

ScanDetail.layout = (props: {
    project: { id: string; name: string };
    scan: { id: string };
}) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Projects', href: projectsIndex() },
        { title: props.project.name, href: projectShow(props.project.id) },
        { title: 'Scan history', href: projectScansRoute(props.project.id) },
        { title: props.scan.id, href: '#' },
    ],
});
