# Findings: Lifecycle, Occurrences, and Persistence

**Status: Implemented (Phase 3).** This documents what actually exists:
persistent `Project`/`Scan`/`Finding`/`FindingOccurrence`/
`FindingStatusHistory`/`ScanAnalyzerExecution` records, ingestion, and
auto-resolution. **No real scanner produces this data yet** — everything
here is exercised with synthetic `FindingCandidate`s in tests. See
[ADR-0010](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md)
for the design decisions behind all of this, and [`findings.md`](findings.md)
for the `Finding` field reference.

## Finding vs. Occurrence

- **Finding** (`findings` table) — the stable, cross-scan **logical
  identity** of an issue: which rule/analyzer, category, severity,
  confidence, descriptive text, lifecycle status, first/last seen. One row
  per distinct issue, however many times it's been re-detected.
- **FindingOccurrence** (`finding_occurrences` table) — the **evidence
  observed in one specific scan**: file path, line range, code snippet,
  context, raw evidence, rule/analyzer version. One row per (finding,
  scan) pair. Old evidence is never overwritten — if a scan re-observes a
  finding, it gets its own occurrence row, so "the code moved from line 42
  to line 58 between scan #10 and #11" stays queryable, not just the
  latest position.

## Identity: fingerprinting

A `Finding`'s identity is a `fingerprint` (SHA-256 hex) plus a
`fingerprint_version` (currently `v1`), computed by
`App\Audit\Findings\Fingerprint\Fingerprinter` from:

```
analyzer id + rule id + normalized file path + normalized code snippet
```

**Deliberately excluded:** line numbers (they shift on unrelated edits —
identity must survive that), and severity/confidence/title (metadata about
the issue, not what makes it the same issue — these are updated on every
re-observation without changing identity). **Deliberately not in the
hash:** the project — matching is scoped to `project_id` via the query and
a database unique constraint on `(project_id, fingerprint,
fingerprint_version)`, so the same fingerprint in two different projects
is (correctly) two different `Finding` rows.

Normalization (`Fingerprinter`'s private methods) is a shallow,
deterministic text transform — line-ending normalization and whitespace
collapsing — not semantic/AST analysis. Two snippets that differ only in
formatting/reindentation fingerprint identically; two snippets with
actually different code do not.

**Versioned from day one.** `fingerprint_version` is stored on every
`Finding` specifically so a smarter future algorithm (v2+) can be
introduced as a disjoint identity space, never silently reinterpreting or
merging into existing history.

**Not a primary key.** `Finding.id` is the real primary key; the
fingerprint is used only to find-or-create a match. See
[ADR-0010](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md#finding-vs-occurrence)
for why a hash collision degrades gracefully rather than corrupting
anything.

## Ingestion

`App\Audit\Findings\Ingestion\FindingIngestor::ingest(Project, Scan,
FindingCandidate)`, per observed candidate:

1. Compute the fingerprint.
2. Look up an existing `Finding` scoped to `(project_id, fingerprint,
fingerprint_version)` — locked (`lockForUpdate()`) inside a transaction,
   so two concurrent scans for the same project can't both decide the
   fingerprint is new and insert a duplicate. (A no-op on SQLite — its
   grammar compiles the lock clause to nothing, relying instead on
   SQLite's own connection-level write serialization — and a real
   row-level lock on MySQL/PostgreSQL.)
3. **Not found** → create a new `Finding` (status `OPEN`), record a
   "created" history entry, `first_seen_at`/`last_seen_at` = now.
4. **Found, status `RESOLVED`** → reopen it (see
   [Regression](#regression--reopening) below).
5. **Found, any other status** → status untouched (see
   [Manual statuses survive re-observation](#manual-statuses-survive-re-observation)).
6. Update descriptive fields (title/severity/confidence/description/etc.)
   to the latest observation's values, and `last_seen_scan_id`/
   `last_seen_at`.
7. Create or update the `FindingOccurrence` for `(finding_id, scan_id)` —
   evidence text is redacted first (see [Redaction](#redaction)).

## Severity and Confidence

Independent axes (unchanged from ADR-0003):

- `Severity` (`CRITICAL|HIGH|MEDIUM|LOW|INFO`) — how bad, if real.
- `Confidence` (`HIGH|MEDIUM|LOW`) — how sure LaraDogs is it's real at all.

A finding can be `Severity::Critical` + `Confidence::Low` (a heuristic
flags something catastrophic-if-true but unconfirmed) just as validly as
`Severity::Low` + `Confidence::High` (a precisely-detected, low-impact
issue). Confidence is a fixed three-level enum rather than a numeric
score — without calibrated data from real scanners yet, a numeric scale
would only imply a precision this project can't back up.

## Lifecycle

Statuses: `OPEN | CONFIRMED | RESOLVED | ACCEPTED_RISK | FALSE_POSITIVE |
IGNORED` (`App\Audit\Findings\FindingStatus`). Every transition — manual
or automatic — goes through
`App\Audit\Findings\Lifecycle\FindingLifecycleService::transition()`, the
only code path allowed to change a Finding's status. It:

- Requires a non-empty `$reason` for transitions **into**
  `ACCEPTED_RISK`/`FALSE_POSITIVE`/`IGNORED` (throws
  `InvalidArgumentException` otherwise) — optional for `OPEN`/`CONFIRMED`/
  `RESOLVED`.
- Writes one `FindingStatusHistory` row per transition (`previous_status`
  null only for the very first, creation, row), inside the same
  transaction as the status update.

### Manual statuses survive re-observation

Re-detecting a finding that's `ACCEPTED_RISK`/`FALSE_POSITIVE`/`IGNORED`
records a new `FindingOccurrence` (the evidence isn't hidden) but **does
not** change its status. These are deliberate human judgments about a
finding that may well still be present — re-detecting it doesn't
invalidate that judgment. Only a human (or a future explicit policy) moves
a finding out of a suppressed status.

### Coverage: why "Passed" alone isn't enough

**`ExecutionStatus::Passed` means the analyzer ran without error — it
does NOT mean the analyzer verified every rule it has ever produced a
finding for.** A rule can be removed, disabled, or simply not loaded into
a given run's ruleset while the analyzer itself still exits cleanly. If
auto-resolution trusted `Passed` alone, a finding could be silently (and
wrongly) marked fixed the moment its rule stopped running — not because
the underlying issue went away, but because nobody was looking for it
anymore.

`App\Audit\Engine\Execution\AnalyzerCoverage` is the analyzer's own,
separate declaration of what it actually verified this run
(`App\Audit\Engine\Execution\CoverageMode`):

- **`Unknown`** — no coverage evidence. The default whenever an analyzer
  declares nothing. Never authorizes resolution, regardless of `status`.
- **`Explicit`** — the analyzer lists the exact `rule_id`s it verified
  this run. Only findings whose `rule_id` is in that list are eligible.
- **`Full`** — the analyzer declares this run covered its entire relevant
  domain (any `rule_id` counts as verified). **Never inferred** from
  `status === Passed` — only set by an explicit analyzer declaration.

An optional `rulesetVersion` travels alongside coverage for future
traceability (e.g. "ruleset 2026.09.1") — it is **provenance only** and is
never consulted by `AnalyzerCoverage::verifies()`. A ruleset version bump
with unchanged coverage authorizes nothing new; coverage changing (even
under an unchanged version) changes everything. Coverage lives on
`AnalyzerResult`/`AnalyzerExecution` (Phase 2) — it's a property of the
analyzer's own execution, not of the Finding domain, so any future
consumer besides reconciliation can read it the same way.

### Dependency/package coverage is a different question (Phase 4.1 research)

`AnalyzerCoverage`'s `Explicit`/`Full` modes were designed around a
**rule-based** analyzer (a static-analysis ruleset that either did or
didn't execute a given rule this run). A **dependency-advisory**
analyzer like `composer-audit` doesn't have "rules" in that sense — it
re-checks locked packages against whatever an external advisory database
currently knows, and only reports what currently matches. Phase 4.1
researched, deliberately, whether this warrants a NEW coverage concept
(e.g. package-level rather than rule-level) instead of reusing
`Explicit`/`Full` as-is — see
[`analyzers/composer-audit.md`](analyzers/composer-audit.md#dependency-coverage-research-phase-41)
for the full investigation. Conclusion: **not yet** — the investigation
surfaced a more fundamental problem than a naming/modeling mismatch (a
target project's own `composer.json` can make an advisory source
disappear from the audit entirely, with no error and no signal in the
JSON output), so `composer-audit` continues to always declare
`AnalyzerCoverage::unknown()` rather than inventing a new coverage
primitive on top of a verification guarantee that doesn't actually exist
yet.

The same conclusion holds for `npm-audit` (Phase 4.2), for the same
underlying reason (no "rules executed" universe) — and Phase 4.2.1's own
registry/proxy trust hardening research doesn't change it either: closing
a trust gap in HOW the audit data is fetched is not the same as gaining a
verifiable universe of WHAT was checked. See
[`analyzers/npm-audit.md`](analyzers/npm-audit.md#10-coverage).

### Auto-resolution safety

**The single most important safety rule in this domain.** A finding may
be auto-resolved ONLY when ALL of the following are true:

1. Its `analyzer_id` completed the scan with `ExecutionStatus::Passed`
   (checked against `scan_analyzer_executions` for that scan — see
   [Scan Analyzer Executions](#scan-analyzer-executions) below).
2. That execution's declared `AnalyzerCoverage` actually
   [verifies](#coverage-why-passed-alone-isnt-enough) the finding's
   `rule_id` — `Unknown` coverage never satisfies this, even under
   `Passed`.
3. It received no new occurrence in that scan.

`App\Audit\Findings\Ingestion\FindingReconciler::reconcile(Project, Scan)`
runs this after all of a scan's candidates are ingested: for each
execution that completed `Passed`, it checks that execution's coverage,
then auto-resolves every `OPEN`/`CONFIRMED` finding belonging to that same
`analyzer_id` whose `rule_id` the coverage verifies and that wasn't
re-observed. Coverage is evaluated per execution, so it never crosses
analyzer boundaries — one analyzer's `Full` coverage can never resolve a
finding that belongs to a different analyzer. Everything else — findings
whose analyzer wasn't part of this scan, was `NotApplicable`,
`Unavailable`, `Failed`, `TimedOut`, `Skipped`, or ran with `Unknown`
coverage (including simply reporting none) — is left **completely
untouched**, no matter how many scans pass without them being verifiably
checked. "Confirmed absent" and "not checked" must never be confused;
that's the reason both `scan_analyzer_executions` and its `coverage`
column exist.

### Regression / reopening

`RESOLVED` is not a permanent state a finding can only leave manually — if
`FindingIngestor` observes a fingerprint again whose `Finding` is currently
`RESOLVED`, it transitions back to `OPEN` automatically, with a history
row recording `previous_status=resolved, new_status=open` and a
system-authored reason ("Reopened automatically: reappeared in scan ...
after being marked resolved."). There is no separate `REGRESSED` status —
the regression is exactly that history row; a report can always derive
"this was a regression" from `previous_status=resolved &&
new_status=open` without a dedicated column.

## Scan Analyzer Executions

`scan_analyzer_executions` persists one row per
`App\Audit\Engine\Execution\AnalyzerExecution` (Phase 2) for a given scan
— analyzer id/name/category, final `ExecutionStatus`, summary,
diagnostics, **coverage** (nullable JSON — the analyzer's declared
`AnalyzerCoverage`, cast via `App\Models\Audit\Casts\AsAnalyzerCoverage`;
a missing/unparseable value casts to `Unknown`, never a more permissive
mode), duration. This is not incidental bookkeeping: it is the data
`FindingReconciler` depends on to know whether auto-resolution is safe.
Without status alone, "this finding didn't reappear" would be
indistinguishable from "this finding's analyzer never even ran"; without
coverage too, it would also be indistinguishable from "this finding's
_rule_ was never even checked, even though the analyzer itself ran fine."

## Diagnostics vs. Findings (recap)

An `AnalyzerDiagnostic` (Phase 2) describes a problem with the analyzer's
own execution (missing binary, malformed output, internal error) —
persisted on `scan_analyzer_executions.diagnostics`. A `Finding` describes
a problem the analyzer found in the analyzed project. These have never
been, and are not now, the same concept.

## Redaction

`App\Audit\Findings\Redaction\EvidenceRedactor` is applied to
`code_snippet`/`context_code` and to string values in a candidate's
`metadata` (one level deep) before anything is persisted — recognizing an
AWS-style access key id and an obvious `SOMETHING_SECRET=value`-shaped
assignment, masking the value while leaving structure intact (e.g.
`AWS_SECRET_ACCESS_KEY=ABCD****************WXYZ`). **This is
defense-in-depth, not a secret scanner** — it does not attempt entropy
analysis or a comprehensive pattern list, and a real secret-detection
capability is future work. Ordinary code is left untouched; the pattern
set is deliberately conservative to avoid false positives mangling
legitimate evidence.

## Database portability

All tables use portable Laravel migration primitives only —
`$table->ulid()`, `$table->string()`/`text()`/`json()`, portable
`foreignId()->constrained()` — no vendor-specific enum types, JSON
operators, generated columns, or partial indexes (per
[ADR-0007](../architecture/decisions/ADR-0007-database-agnostic-persistence.md)).
Every enum (`Severity`, `Confidence`, `FindingStatus`, `ScanStatus`,
`ActorType`, plus Phase 2's `AnalyzerCategory`/`ExecutionStatus`) is stored
as a plain string column and cast to a PHP backed enum by Eloquent —
readable by any of LaraDogs' four supported databases without a
database-level enum type. `project_profile`/`environment`/
`findings_summary`/`diagnostics`/`evidence`/`references`/`metadata` use
Laravel's portable `json()` column and are treated purely as storage —
nothing queries into their internal structure with database-specific JSON
operators.

`lockForUpdate()` (used during ingestion) compiles to a real row lock on
MySQL/PostgreSQL and a documented no-op on SQLite (whose grammar compiles
the lock clause to nothing) — safe on all three because SQLite already
serializes writers at the connection/transaction level.

## Concurrency limits

Ingestion for one candidate runs inside a single `DB::transaction()`.
Within that transaction, `FindingIngestor` looks up a matching `Finding`
with `lockForUpdate()` — **but precisely stated, this only locks a row
that already exists.** If two concurrent transactions both run their
lookup before either has inserted anything, both see "no matching row"
and neither is blocked by the lock (there is nothing yet to lock); both
may then attempt to insert a `Finding` for the same
`(project_id, fingerprint, fingerprint_version)`.

The **actual final guarantee** against a duplicate in that race is the
database's **unique constraint** on `(project_id, fingerprint,
fingerprint_version)`: the second transaction's insert is rejected by the
database itself (a `QueryException`), never silently creating a second
row for the same logical identity — verified directly by a dedicated test
(`FindingIngestionTest`) that performs two conflicting inserts and asserts
the second throws. `lockForUpdate()` still matters for the (far more
common) case where the row already exists by the time a second ingestion
reads it — e.g. two scans updating the same finding's `last_seen_at`
concurrently — but it is not, by itself, what prevents a duplicate
_creation_.

Today, a real create/create race surfaces as a thrown `QueryException`
from `FindingIngestor::ingest()` — `ScanRecorder::completeScan()` catches
it, marks the `Scan` `Failed`, and re-throws; there is no automatic
retry-and-re-fetch. This is a deliberate, safe failure mode (loud and
non-corrupting, consistent with this domain's fail-closed philosophy), not
a claim that the race can't happen. This is not a distributed-cluster
solution — it assumes a single database connection/transaction boundary
per ingestion, which is what LaraDogs' current single-process model
provides. Nothing here has been tested against, or is claimed to work
under, multi-node concurrent writers or sophisticated retry policies —
both remain explicitly out of scope.

## IDs

Every `Project`/`Scan`/`Finding` has both an internal auto-incrementing
`id` (never exposed) and a `public_id` (ULID, via Laravel's native
`HasUlids` — no new dependency) suitable for future external references
(MCP `get_finding(id)`, a dashboard URL). A human-friendly sequential
display key (e.g. `SEC-0042`) is deliberately **not** built now — it would
need a counter scoped somehow (per-project? per-category? global?) that
this phase has no real requirement to design yet; `public_id` is the only
external identifier today, and a display key can be added later without
touching it.

## Not built in this phase

- Any real scanner/analyzer producing `FindingCandidate`s (Phase 4+).
- A `Finding` display/detail UI, scan comparison view, or any dashboard
  surface (Phase 7+) — though nothing here was denormalized prematurely
  to anticipate one.
- The MCP server (Phase 9) — `public_id` exists so `get_finding`/
  `list_findings`/`get_scan`-shaped MCP tools have something stable to key
  off later.
- A human-friendly sequential display key, richer fail-fast/per-analyzer
  timeout policy (Phase 2 already covers the base case), a real secret
  scanner, and multi-connection database evidence — see
  [ADR-0010](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md#consequences).
- Automatic retry-and-re-fetch on a create/create race (see
  [Concurrency limits](#concurrency-limits)) — today it's a loud, safe
  failure, not a silent retry.
- Any real ruleset/rules-pack system — `AnalyzerCoverage.rulesetVersion`
  is a plain optional string for future provenance, not a versioned rules
  package format.
