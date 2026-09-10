/**
 * Frontend mirror of the backend enums in `App\Audit\Findings\*` /
 * `App\Audit\Engine\*` — centralized here once so a server-side enum
 * rename doesn't need touching a dozen scattered string literals. Every
 * value is the exact `->value` the backend serializes.
 */

export type Severity =
    | 'critical'
    | 'high'
    | 'medium'
    | 'low'
    | 'info'
    | 'unknown';

export type Confidence = 'high' | 'medium' | 'low';

export type FindingStatus =
    | 'open'
    | 'confirmed'
    | 'resolved'
    | 'accepted_risk'
    | 'false_positive'
    | 'ignored';

export type ScanStatus = 'running' | 'completed' | 'failed';

export type AnalyzerCategory =
    | 'security'
    | 'bug'
    | 'performance'
    | 'dependency'
    | 'quality'
    | 'configuration'
    | 'test';

export type ExecutionStatus =
    | 'planned'
    | 'passed'
    | 'failed'
    | 'timed_out'
    | 'skipped'
    | 'not_applicable'
    | 'unavailable';

export type CoverageMode = 'unknown' | 'explicit' | 'full';

export const FINDING_STATUSES_REQUIRING_REASON: readonly FindingStatus[] = [
    'accepted_risk',
    'false_positive',
    'ignored',
];

export const SUPPRESSED_FINDING_STATUSES: readonly FindingStatus[] = [
    'accepted_risk',
    'false_positive',
    'ignored',
];

export type Coverage = {
    mode: CoverageMode;
    rule_ids: string[];
};

export type AnalyzerExecutionSummary = {
    analyzer_id: string;
    analyzer_name: string;
    category?: AnalyzerCategory;
    status: ExecutionStatus;
    summary: string | null;
    duration_ms: number | null;
    note?: string | null;
    coverage?: Coverage;
    diagnostics?: Array<{ level: string; message: string }> | null;
};

export type ScanSummary = {
    id: string;
    status: ScanStatus;
    started_at: string;
    finished_at: string | null;
    duration_ms?: number | null;
    findings_summary?: {
        observed: number;
        auto_resolved: number;
        by_severity: Record<string, number>;
    } | null;
};

export type FindingSummary = {
    id: string;
    rule_id: string;
    analyzer_id: string;
    category: AnalyzerCategory;
    severity: Severity;
    confidence: Confidence;
    status: FindingStatus;
    title: string;
    first_seen_at?: string;
    last_seen_at: string;
};

export type Pagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export type FindingFilterState = {
    status: string | null;
    severity: string | null;
    category: string | null;
    analyzer_id: string | null;
    rule_id: string | null;
};
