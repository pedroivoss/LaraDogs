import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowRight, ShieldAlert, Terminal } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AnalyzerStatusBadge } from '@/components/audit/analyzer-status-badge';
import { CoverageBadge } from '@/components/audit/coverage-badge';
import { EmptyState } from '@/components/audit/empty-state';
import { FindingStatusBadge } from '@/components/audit/finding-status-badge';
import { SeverityBadge } from '@/components/audit/severity-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { dashboard } from '@/routes';
import { show as findingShow } from '@/routes/findings';
import {
    findings as projectFindings,
    index as projectsIndex,
    scans as projectScans,
    show as projectShow,
} from '@/routes/projects';
import { update as updateAuditSchedule } from '@/routes/projects/audit-schedule';
import { store as storeAudit } from '@/routes/projects/audits';
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

type ActiveScan = {
    id: string;
    status: 'queued' | 'running';
    started_at: string;
    running_at: string | null;
};

type Schedule = {
    audit_schedule: 'disabled' | 'daily' | 'weekly' | 'monthly';
    audit_schedule_day_of_week: number | null;
    audit_schedule_day_of_month: number | null;
    next_audit_at: string | null;
    last_scheduled_audit_at: string | null;
};

const WEEKDAYS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

export default function ProjectShow({
    project,
    profile,
    summary,
    analyzer_executions,
    recent_scans,
    recent_findings,
    audit_command,
    active_scan,
    schedule,
    can_manage_audits: canManageAudits,
}: {
    project: { id: string; name: string; path: string };
    profile: ProjectProfile | null;
    summary: Summary;
    analyzer_executions: AnalyzerExecutionSummary[];
    recent_scans: ScanSummary[];
    recent_findings: FindingSummary[];
    audit_command: string;
    active_scan: ActiveScan | null;
    schedule: Schedule;
    can_manage_audits: boolean;
}) {
    const laravelVersion =
        profile?.backend?.laravel?.installed_version ??
        profile?.backend?.laravel?.constraint;
    const phpVersion =
        profile?.backend?.php?.installed_version ??
        profile?.backend?.php?.constraint;

    const [submitting, setSubmitting] = useState(false);

    const { start, stop } = usePoll(
        4000,
        {
            only: [
                'active_scan',
                'summary',
                'analyzer_executions',
                'recent_scans',
                'recent_findings',
                'schedule',
            ],
        },
        { autoStart: false },
    );

    useEffect(() => {
        if (active_scan !== null) {
            start();
        } else {
            stop();
        }
    }, [active_scan, start, stop]);

    function runAudit() {
        setSubmitting(true);
        router.post(
            storeAudit(project.id).url,
            {},
            { onFinish: () => setSubmitting(false) },
        );
    }

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
                        <CardTitle className="text-sm">Audit</CardTitle>
                        {active_scan && (
                            <Badge
                                variant={
                                    active_scan.status === 'running'
                                        ? 'default'
                                        : 'outline'
                                }
                            >
                                {active_scan.status === 'running'
                                    ? 'Running'
                                    : 'Queued'}
                            </Badge>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {active_scan ? (
                            <p className="text-muted-foreground text-sm">
                                Audit {active_scan.status} —{' '}
                                <ElapsedTime
                                    since={
                                        active_scan.running_at ??
                                        active_scan.started_at
                                    }
                                />
                            </p>
                        ) : canManageAudits ? (
                            <Button
                                onClick={runAudit}
                                disabled={submitting}
                                size="sm"
                            >
                                {submitting ? 'Queuing…' : 'Run Audit'}
                            </Button>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                Only an Owner or Admin can start an audit.
                            </p>
                        )}

                        <div>
                            <p className="text-muted-foreground mb-2 text-xs">
                                Or from the command line:
                            </p>
                            <div className="bg-muted flex items-center gap-2 rounded-md px-3 py-2 font-mono text-xs">
                                <Terminal
                                    className="text-muted-foreground size-3.5 shrink-0"
                                    aria-hidden
                                />
                                {audit_command}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <AutomaticAuditsCard
                    projectId={project.id}
                    schedule={schedule}
                    canManage={canManageAudits}
                />

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

function ElapsedTime({ since }: { since: string }) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const interval = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(interval);
    }, []);

    const seconds = Math.max(
        0,
        Math.floor((now - new Date(since).getTime()) / 1000),
    );
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    return (
        <span>
            {minutes}m {remainingSeconds}s elapsed
        </span>
    );
}

const FREQUENCY_LABELS: Record<Schedule['audit_schedule'], string> = {
    disabled: 'Disabled',
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
};

function AutomaticAuditsCard({
    projectId,
    schedule,
    canManage,
}: {
    projectId: string;
    schedule: Schedule;
    canManage: boolean;
}) {
    const [frequency, setFrequency] = useState(schedule.audit_schedule);
    const [dayOfWeek, setDayOfWeek] = useState(
        schedule.audit_schedule_day_of_week ?? 1,
    );
    const [dayOfMonth, setDayOfMonth] = useState(
        schedule.audit_schedule_day_of_month ?? 1,
    );

    if (!canManage) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-sm">Automatic audits</CardTitle>
                </CardHeader>
                <CardContent className="space-y-1 text-sm">
                    <p>
                        Status:{' '}
                        <span className="text-muted-foreground">
                            {FREQUENCY_LABELS[schedule.audit_schedule]}
                        </span>
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {schedule.next_audit_at
                            ? `Next automatic audit: ${new Date(schedule.next_audit_at).toLocaleString()}`
                            : 'No automatic audit is scheduled.'}
                    </p>
                    {schedule.last_scheduled_audit_at && (
                        <p className="text-muted-foreground text-xs">
                            Last automatic audit:{' '}
                            {new Date(
                                schedule.last_scheduled_audit_at,
                            ).toLocaleString()}
                        </p>
                    )}
                    <p className="text-muted-foreground text-xs">
                        Only an Owner or Admin can change this.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">Automatic audits</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <Form
                    action={updateAuditSchedule(projectId).url}
                    method="put"
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing }) => (
                        <>
                            <div className="flex flex-col gap-1.5">
                                <label className="text-muted-foreground text-xs">
                                    Frequency
                                </label>
                                <Select
                                    value={frequency}
                                    onValueChange={(value) =>
                                        setFrequency(
                                            value as Schedule['audit_schedule'],
                                        )
                                    }
                                >
                                    <SelectTrigger className="w-40">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="disabled">
                                            Disabled
                                        </SelectItem>
                                        <SelectItem value="daily">
                                            Daily
                                        </SelectItem>
                                        <SelectItem value="weekly">
                                            Weekly
                                        </SelectItem>
                                        <SelectItem value="monthly">
                                            Monthly
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="audit_schedule"
                                    value={frequency}
                                />
                            </div>

                            {frequency === 'weekly' && (
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-muted-foreground text-xs">
                                        Day of week
                                    </label>
                                    <Select
                                        value={String(dayOfWeek)}
                                        onValueChange={(value) =>
                                            setDayOfWeek(Number(value))
                                        }
                                    >
                                        <SelectTrigger className="w-40">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {WEEKDAYS.map((day, index) => (
                                                <SelectItem
                                                    key={day}
                                                    value={String(index)}
                                                >
                                                    {day}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <input
                                        type="hidden"
                                        name="audit_schedule_day_of_week"
                                        value={dayOfWeek}
                                    />
                                </div>
                            )}

                            {frequency === 'monthly' && (
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-muted-foreground text-xs">
                                        Day of month
                                    </label>
                                    <Select
                                        value={String(dayOfMonth)}
                                        onValueChange={(value) =>
                                            setDayOfMonth(Number(value))
                                        }
                                    >
                                        <SelectTrigger className="w-40">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Array.from(
                                                { length: 31 },
                                                (_, index) => index + 1,
                                            ).map((day) => (
                                                <SelectItem
                                                    key={day}
                                                    value={String(day)}
                                                >
                                                    {day}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <input
                                        type="hidden"
                                        name="audit_schedule_day_of_month"
                                        value={dayOfMonth}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Clamped to the last day of shorter
                                        months.
                                    </p>
                                </div>
                            )}

                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                Save
                            </Button>
                        </>
                    )}
                </Form>

                <div className="text-muted-foreground space-y-1 text-xs">
                    {schedule.next_audit_at && (
                        <p>
                            Next automatic audit:{' '}
                            {new Date(schedule.next_audit_at).toLocaleString()}
                        </p>
                    )}
                    {schedule.last_scheduled_audit_at && (
                        <p>
                            Last automatic audit:{' '}
                            {new Date(
                                schedule.last_scheduled_audit_at,
                            ).toLocaleString()}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
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
