import { Head, Link, router } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { ConfidenceBadge } from '@/components/audit/confidence-badge';
import { DataPagination } from '@/components/audit/data-pagination';
import { EmptyState } from '@/components/audit/empty-state';
import { FindingStatusBadge } from '@/components/audit/finding-status-badge';
import { SeverityBadge } from '@/components/audit/severity-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { show as findingShow } from '@/routes/findings';
import {
    findings as projectFindingsRoute,
    index as projectsIndex,
    show as projectShow,
} from '@/routes/projects';
import type {
    FindingFilterState,
    FindingSummary,
    Pagination,
} from '@/types/audit';

const STATUS_OPTIONS = [
    'open',
    'confirmed',
    'resolved',
    'accepted_risk',
    'false_positive',
    'ignored',
];
const SEVERITY_OPTIONS = [
    'critical',
    'high',
    'medium',
    'low',
    'info',
    'unknown',
];
const CATEGORY_OPTIONS = [
    'security',
    'bug',
    'performance',
    'dependency',
    'quality',
    'configuration',
    'test',
];

export default function ProjectFindings({
    project,
    findings,
    pagination,
    filters,
}: {
    project: { id: string; name: string };
    findings: FindingSummary[];
    pagination: Pagination;
    filters: FindingFilterState;
}) {
    const [ruleId, setRuleId] = useState(filters.rule_id ?? '');
    const [analyzerId, setAnalyzerId] = useState(filters.analyzer_id ?? '');

    function applyFilters(next: Partial<FindingFilterState>) {
        router.get(
            projectFindingsRoute(project.id),
            {
                status: next.status ?? filters.status ?? undefined,
                severity: next.severity ?? filters.severity ?? undefined,
                category: next.category ?? filters.category ?? undefined,
                analyzer_id:
                    next.analyzer_id ?? filters.analyzer_id ?? undefined,
                rule_id: next.rule_id ?? filters.rule_id ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function goToPage(page: number) {
        router.get(
            projectFindingsRoute(project.id),
            { ...filters, page },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const hasActiveFilters = Object.values(filters).some(
        (value) => value !== null && value !== undefined && value !== '',
    );

    return (
        <>
            <Head title={`Findings — ${project.name}`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Findings</h1>

                <div className="flex flex-wrap items-end gap-3">
                    <FilterSelect
                        label="Status"
                        value={filters.status}
                        options={STATUS_OPTIONS}
                        onChange={(value) => applyFilters({ status: value })}
                    />
                    <FilterSelect
                        label="Severity"
                        value={filters.severity}
                        options={SEVERITY_OPTIONS}
                        onChange={(value) => applyFilters({ severity: value })}
                    />
                    <FilterSelect
                        label="Category"
                        value={filters.category}
                        options={CATEGORY_OPTIONS}
                        onChange={(value) => applyFilters({ category: value })}
                    />
                    <div className="flex flex-col gap-1.5">
                        <label className="text-muted-foreground text-xs">
                            Analyzer
                        </label>
                        <Input
                            value={analyzerId}
                            onChange={(event) =>
                                setAnalyzerId(event.target.value)
                            }
                            onBlur={() =>
                                applyFilters({
                                    analyzer_id: analyzerId || null,
                                })
                            }
                            placeholder="e.g. semgrep"
                            className="h-9 w-40"
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label className="text-muted-foreground text-xs">
                            Rule ID
                        </label>
                        <Input
                            value={ruleId}
                            onChange={(event) => setRuleId(event.target.value)}
                            onBlur={() =>
                                applyFilters({ rule_id: ruleId || null })
                            }
                            placeholder="e.g. laradogs.security.*"
                            className="h-9 w-48"
                        />
                    </div>
                    {hasActiveFilters && (
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={projectFindingsRoute(project.id)}>
                                Clear filters
                            </Link>
                        </Button>
                    )}
                </div>

                {findings.length === 0 ? (
                    <EmptyState
                        icon={ShieldAlert}
                        title={
                            hasActiveFilters
                                ? 'No findings matching these filters'
                                : 'No current findings'
                        }
                        description={
                            hasActiveFilters
                                ? 'Try widening or clearing the filters above.'
                                : 'No current findings were reported by the analyzers that completed with sufficient coverage.'
                        }
                    />
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Rule / Finding</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Confidence</TableHead>
                                        <TableHead>Last seen</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {findings.map((finding) => (
                                        <TableRow key={finding.id}>
                                            <TableCell>
                                                <SeverityBadge
                                                    severity={finding.severity}
                                                />
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-xs">
                                                {finding.category}
                                            </TableCell>
                                            <TableCell className="max-w-md whitespace-normal">
                                                <Link
                                                    href={findingShow(
                                                        finding.id,
                                                    )}
                                                    className="font-medium break-words hover:underline"
                                                >
                                                    {finding.title}
                                                </Link>
                                                <p className="text-muted-foreground font-mono text-xs break-all">
                                                    {finding.rule_id}
                                                </p>
                                            </TableCell>
                                            <TableCell>
                                                <FindingStatusBadge
                                                    status={finding.status}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <ConfidenceBadge
                                                    confidence={
                                                        finding.confidence
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-xs">
                                                {new Date(
                                                    finding.last_seen_at,
                                                ).toLocaleDateString()}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <DataPagination
                            pagination={pagination}
                            onPageChange={goToPage}
                        />
                    </>
                )}
            </div>
        </>
    );
}

function FilterSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string | null;
    options: string[];
    onChange: (value: string | null) => void;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <label className="text-muted-foreground text-xs">{label}</label>
            <Select
                value={value ?? 'all'}
                onValueChange={(next) => onChange(next === 'all' ? null : next)}
            >
                <SelectTrigger className="h-9 w-40">
                    <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option} value={option}>
                            {option}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

ProjectFindings.layout = (props: {
    project: { id: string; name: string };
}) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Projects', href: projectsIndex() },
        { title: props.project.name, href: projectShow(props.project.id) },
        { title: 'Findings', href: projectFindingsRoute(props.project.id) },
    ],
});
