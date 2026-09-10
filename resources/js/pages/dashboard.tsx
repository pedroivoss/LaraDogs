import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    FolderOpen,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { AnalyzerStatusBadge } from '@/components/audit/analyzer-status-badge';
import { EmptyState } from '@/components/audit/empty-state';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type { AnalyzerExecutionSummary, ScanSummary } from '@/types/audit';

type DashboardSummary = {
    total_projects: number;
    projects_with_open_findings: number;
    total_open_findings: number;
    critical_open_findings: number;
    high_open_findings: number;
    recent_scans: Array<
        ScanSummary & { project: { id: string; name: string } }
    >;
    analyzer_problems: Array<
        AnalyzerExecutionSummary & {
            scan: { id: string; project: { id: string; name: string } };
        }
    >;
};

export default function Dashboard({ summary }: { summary: DashboardSummary }) {
    if (summary.total_projects === 0) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="flex flex-1 flex-col gap-4 p-4">
                    <EmptyState
                        icon={FolderOpen}
                        title="No projects registered yet"
                        description="Register a Laravel project with LaraDogs to start tracking its audit history. This is currently a CLI-only workflow."
                        action={
                            <code className="bg-muted rounded-md px-3 py-1.5 text-xs">
                                php artisan laradogs:project:add
                                /path/to/your/laravel/project
                            </code>
                        }
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard
                        icon={FolderOpen}
                        label="Projects"
                        value={summary.total_projects}
                        sub={`${summary.projects_with_open_findings} with open findings`}
                    />
                    <SummaryCard
                        icon={ShieldAlert}
                        label="Open findings"
                        value={summary.total_open_findings}
                    />
                    <SummaryCard
                        icon={AlertTriangle}
                        label="Critical"
                        value={summary.critical_open_findings}
                        tone="critical"
                    />
                    <SummaryCard
                        icon={AlertTriangle}
                        label="High"
                        value={summary.high_open_findings}
                        tone="high"
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Recent scans
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {summary.recent_scans.length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    No scans have been recorded yet.
                                </p>
                            )}
                            {summary.recent_scans.map((scan) => (
                                <Link
                                    key={scan.id}
                                    href={projectShow(scan.project.id)}
                                    className="hover:bg-muted/50 flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                                >
                                    <span className="font-medium">
                                        {scan.project.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {new Date(
                                            scan.started_at,
                                        ).toLocaleString()}
                                    </span>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Analyzer problems in recent scans
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {summary.analyzer_problems.length === 0 && (
                                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                    <ShieldCheck className="size-4" /> No
                                    analyzer failures/timeouts among the recent
                                    scans shown here.
                                </p>
                            )}
                            {summary.analyzer_problems.map(
                                (execution, index) => (
                                    <Link
                                        key={index}
                                        href={projectShow(
                                            execution.scan.project.id,
                                        )}
                                        className="hover:bg-muted/50 flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {execution.scan.project.name}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {execution.analyzer_name}
                                            </p>
                                        </div>
                                        <AnalyzerStatusBadge
                                            status={execution.status}
                                        />
                                    </Link>
                                ),
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Link
                    href={projectsIndex()}
                    className="text-primary text-sm hover:underline"
                >
                    View all projects →
                </Link>
            </div>
        </>
    );
}

function SummaryCard({
    icon: Icon,
    label,
    value,
    sub,
    tone,
}: {
    icon: typeof FolderOpen;
    label: string;
    value: number;
    sub?: string;
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
            <CardContent className="flex items-center gap-3 pt-6">
                <Icon
                    className={`size-5 ${toneClass || 'text-muted-foreground'}`}
                    aria-hidden
                />
                <div>
                    <p className={`text-2xl font-semibold ${toneClass}`}>
                        {value}
                    </p>
                    <p className="text-muted-foreground text-xs">{label}</p>
                    {sub && (
                        <p className="text-muted-foreground text-xs">{sub}</p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
