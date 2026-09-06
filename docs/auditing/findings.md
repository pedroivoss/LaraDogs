# Findings

**Status: Implemented (Phase 3).** `Finding`/`FindingOccurrence` are real,
persistent, tested models — see
[`findings-lifecycle.md`](findings-lifecycle.md) for ingestion, lifecycle,
and auto-resolution safety, and
[ADR-0010](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md)
for the design decisions. **No real scanner produces a `Finding` yet** —
that's Phase 4+; everything here is exercised with synthetic
`FindingCandidate`s in tests.

A finding is the atomic unit of everything LaraDogs reports — never raw
scanner stdout, a Semgrep result, a Composer advisory, or a CVE directly;
those get normalized into a `FindingCandidate` first (see
[`findings-lifecycle.md`](findings-lifecycle.md#ingestion)).

## Finding vs. Occurrence

A `Finding` is the stable, cross-scan **identity** of an issue. The
evidence for one specific scan (file/line/snippet) is a separate
`FindingOccurrence` row, so re-detecting the same issue after the code
around it changed doesn't overwrite older evidence — see
[`findings-lifecycle.md`](findings-lifecycle.md#finding-vs-occurrence).

## Fields (`findings` table)

| Field                                              | Notes                                                                                                                                                                                     |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                                               | Internal auto-incrementing primary key. Never exposed externally.                                                                                                                         |
| `public_id`                                        | ULID — the stable external identifier (future MCP/dashboard use).                                                                                                                         |
| `project_id`                                       | Which `Project` this finding belongs to — part of its identity boundary (see fingerprint below).                                                                                          |
| `fingerprint` / `fingerprint_version`              | Cross-scan identity — see [`findings-lifecycle.md`](findings-lifecycle.md#identity-fingerprinting). **Not** the primary key.                                                              |
| `rule_id`                                          | Which rule/check produced this.                                                                                                                                                           |
| `analyzer_id`                                      | Which analyzer produced this — also what [auto-resolution safety](findings-lifecycle.md#auto-resolution-safety) keys off.                                                                 |
| `category`                                         | Reuses `App\Audit\Engine\Contracts\AnalyzerCategory` (Phase 2) directly — `SECURITY \| BUG \| PERFORMANCE \| DEPENDENCY \| QUALITY \| CONFIGURATION \| TEST`. No separate/duplicate enum. |
| `severity`                                         | See [`severity.md`](severity.md).                                                                                                                                                         |
| `confidence`                                       | See [`confidence.md`](confidence.md) — independent of severity.                                                                                                                           |
| `title`, `description`, `impact`, `recommendation` | Human-facing explanation. Updated to the latest observation's values on every re-observation.                                                                                             |
| `cwe`, `cve`                                       | When applicable — both nullable; a dependency finding may have a CVE and no CWE, a config finding may have neither.                                                                       |
| `references`                                       | JSON list of URLs.                                                                                                                                                                        |
| `metadata`                                         | JSON, analyzer-specific extra data — redacted (see below) before persistence.                                                                                                             |
| `status`, `status_reason`                          | See [Lifecycle](findings-lifecycle.md#lifecycle). `status_reason` mirrors the latest history entry's reason for convenient reads; the history table is the source of truth.               |
| `first_seen_scan_id`, `first_seen_at`              | Which scan first observed this finding, and when.                                                                                                                                         |
| `last_seen_scan_id`, `last_seen_at`                | Most recent scan that actually observed it (i.e. created an occurrence) — not merely "the most recent scan of this project."                                                              |

Not every field applies to every category — a dependency finding
typically has no `line_start`/`line_end`/`code_snippet` (those live on
`FindingOccurrence`, all nullable); a configuration finding may have
neither a snippet nor a CWE. Nothing here is forced non-null just because
a security finding usually has it.

## Fields (`finding_occurrences` table)

| Field                                 | Notes                                                                                                                                                   |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `finding_id`, `scan_id`               | Unique together — at most one occurrence per finding per scan.                                                                                          |
| `file_path`, `line_start`, `line_end` | Nullable — a dependency finding has none of these.                                                                                                      |
| `code_snippet`, `context_code`        | Nullable; redacted before storage (see below).                                                                                                          |
| `evidence`                            | JSON, analyzer-specific structured evidence; string values redacted one level deep.                                                                     |
| `rule_version`, `analyzer_version`    | For distinguishing "the code changed" from "the rule/analyzer changed" — see [`findings-lifecycle.md`](findings-lifecycle.md#scan-analyzer-executions). |
| `observed_at`                         | When this occurrence was recorded.                                                                                                                      |

## Secrets are redacted, not stored

If a finding's evidence contains something that looks like a secret (an
AWS-style access key id, an obvious `SOMETHING_SECRET=value` assignment),
`App\Audit\Findings\Redaction\EvidenceRedactor` masks the value before
persistence — even in LaraDogs' own database. This is **defense-in-depth,
not a secret scanner**; see
[`findings-lifecycle.md`](findings-lifecycle.md#redaction) for exactly
what it does and doesn't catch.

## Findings don't silently disappear

A finding that stops being reported isn't deleted — and isn't even marked
resolved unless it's actually safe to conclude that (see
[Auto-resolution safety](findings-lifecycle.md#auto-resolution-safety)).
Every status change, automatic or manual, is recorded in
`finding_status_histories` — see
[`findings-lifecycle.md`](findings-lifecycle.md#lifecycle) and
[`suppressions.md`](suppressions.md).
