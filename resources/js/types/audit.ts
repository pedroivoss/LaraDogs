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

export type ScanStatus = 'queued' | 'running' | 'completed' | 'failed';

export type ScanOrigin = 'manual' | 'scheduled' | 'cli';

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

export type SourceType = 'git' | 'none' | 'bare' | 'unavailable';

/**
 * Immutable-or-current Git source metadata (Phase 9). Never an absolute host
 * path, credentials or an author identity. `consistent` exists only on a
 * persisted scan's snapshot: `false` = the source changed while that audit
 * ran; `null` = no verifiable source identity.
 */
export type SourceInfo = {
    type: SourceType;
    label: string;
    commit: string | null;
    short_commit: string | null;
    branch: string | null;
    detached: boolean | null;
    dirty: boolean | null;
    commit_at: string | null;
    commit_subject: string | null;
    remote: string | null;
    reproducible: boolean;
    reason: string | null;
    consistent?: boolean | null;
    /** Why integrity was NOT established (null when verified / no Git claim). */
    integrity_reason?: SourceIntegrityReason | null;
    /** true only for a demonstrated mutation, false for "could not be proven". */
    integrity_changed?: boolean;
    integrity_message?: string | null;
};

export type SourceIntegrityReason =
    | 'changed_during_audit'
    | 'dirty_at_start'
    | 'no_commits'
    | 'bare_repository'
    | 'unavailable'
    | 'unsafe_config';

export type SourceOverview = {
    current: SourceInfo;
    last_audited: { scan_id: string; source: SourceInfo | null } | null;
    changed_since_last_audit: boolean | null;
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
    source?: SourceInfo | null;
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

/** Quality Gate (Phase 8): a policy result, separate from scan status. */
export type GateOutcome = 'passed' | 'failed' | 'indeterminate';

export type GateSummary = {
    outcome: GateOutcome;
    label: string;
    policy_revision: number;
    rules_total: number;
    rules_failed: number;
    rules_indeterminate: number;
    headline?: string | null;
};

export type GateRuleResult = {
    rule_id: string;
    subject: string | null;
    outcome: GateOutcome;
    label: string;
    summary: string;
    observed: string | null;
    expected: string | null;
    analyzer_id: string | null;
    severity: string | null;
    finding_count: number;
    finding_ids: string[];
};

export type GateDetail = GateSummary & {
    evaluated_at: string;
    baseline_scan_id: string | null;
    policy: unknown;
    rules: GateRuleResult[];
};
