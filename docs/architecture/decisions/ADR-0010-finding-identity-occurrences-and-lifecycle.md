# ADR-0010: Finding Identity, Occurrences, and Lifecycle

## Status

Accepted (Phase 3). Supersedes ADR-0003's status (Proposed → Implemented)
without changing its core decisions; this ADR resolves the details ADR-0003
deliberately left open (exact fingerprint algorithm, occurrence/history
split, auto-resolution safety).

**Amended (Phase 3.1 — Safe Finding Resolution Coverage):** the original
auto-resolution rule below ("its own analyzer completed this scan with
`Passed`") was necessary but not sufficient, and has been tightened. See
[Amendment: coverage-gated auto-resolution](#amendment-phase-31-coverage-gated-auto-resolution)
at the end of this ADR. Everything else in this ADR is unchanged.

## Context

ADR-0003 fixed two constraints for the `Finding` domain — severity and
confidence are independent axes, and a fingerprint must not be
line-number-only — without designing the schema. Phase 3 has to turn that
into real, persistent, portable tables plus the reconciliation logic that
makes re-scanning meaningful. Three questions had no answer yet and needed
one before any of this could be built:

1. **What survives across scans, and what doesn't?** A finding's identity
   (rule + location) is stable; the exact evidence (line number, exact
   snippet) is not. Storing both in one row means either overwriting
   evidence on every scan (losing history) or never updating it (stale
   data).
2. **When is it safe to say a finding is fixed?** A finding that stops
   appearing could mean the bug was fixed — or that the analyzer that used
   to catch it didn't run this time (unavailable, failed, timed out, or
   was dropped from the registry). Getting this wrong either hides real
   bugs (false "resolved") or never lets anything resolve (findings pile
   up forever).
3. **How much does the fingerprint algorithm need to get right on day
   one?** No real scanner exists yet to test against, but the algorithm's
   _version_ has to exist from the start, or a future improvement would
   either silently reinterpret history or require a disruptive migration.

## Decision

### Finding vs. Occurrence

A `Finding` (`findings` table) is the stable, cross-scan **logical
identity** of an issue: rule, category, severity, confidence, descriptive
text, lifecycle status, first/last seen. A `FindingOccurrence`
(`finding_occurrences` table) is the **evidence observed in one specific
scan**: file/line/snippet/context/raw evidence for that run only. Every
scan that re-observes the same Finding creates a new occurrence row — old
evidence is never overwritten, so "the code moved from line 42 to line 58"
and "the exact snippet changed" both stay queryable per scan, not just as
the latest state.

### Fingerprinting (v1)

Computed by `App\Audit\Findings\Fingerprint\Fingerprinter` from `analyzer
id + rule id + normalized file path + normalized code snippet`, hashed
with SHA-256. Explicitly excluded: **line numbers** (the whole point —
identity must survive unrelated edits), and severity/confidence/title
(metadata about the issue, not what makes it the same issue). The project
is deliberately **not** part of the hash input — it's enforced instead by
scoping every match query to `project_id` and by a database unique
constraint on `(project_id, fingerprint, fingerprint_version)`. This keeps
"the fingerprint" meaning one thing (this issue, this location) while
"which project" stays a separate, explicit boundary.

Normalization is a shallow, deterministic text transform (line-ending
normalization, whitespace collapsing) — **not** AST/semantic analysis.
That's consistent with Project Discovery's own precedent
([ADR-0008](ADR-0008-static-project-discovery.md)) of not solving semantic
understanding speculatively; if a real need for smarter normalization
emerges once real scanners exist, that's a new fingerprint version, not a
retrofit of v1.

**Versioned, from day one** (`Fingerprinter::VERSION = 'v1'`, stored on
every `Finding` as `fingerprint_version`). A future v2 algorithm doesn't
reinterpret or silently merge into v1 identities — a new algorithm version
means a disjoint identity space, decided explicitly when it happens, not
guessed at now.

**Not a primary key.** `Finding.id` (an auto-incrementing integer) is the
real primary key everywhere occurrences/history reference it
(`finding_id`). The fingerprint is used only for find-or-create matching.
A SHA-256 collision between two genuinely different issues is
astronomically unlikely but not impossible; if it ever happened, the
practical failure mode is one occurrence attributed to the wrong (but
still perfectly valid) `Finding` row — not a broken schema, dangling
reference, or corrupted primary key. This is an accepted, documented v1
trade-off, not something engineered around further at this stage.

### Lifecycle and auto-resolution safety

Statuses: `OPEN | CONFIRMED | RESOLVED | ACCEPTED_RISK | FALSE_POSITIVE |
IGNORED` (unchanged from ADR-0003/suppressions.md). `REGRESSED` is
**not** a status — a `RESOLVED` finding reappearing transitions back to
`OPEN`, with the regression recorded as a `finding_status_histories` row
(`previous_status = resolved`, `new_status = open`), inferable from the
transition itself rather than needing its own persistent state a finding
could get stuck in.

**The critical safety rule (Phase 3 original — see the Phase 3.1 amendment
below, which tightens condition 1):** a finding may be auto-resolved ONLY
when:

1. Its `analyzer_id` completed the current scan with
   `ExecutionStatus::Passed` (persisted per-scan in
   `scan_analyzer_executions` — see below), AND
2. It received no new occurrence in that scan.

Phase 3.1 found this insufficient: `Passed` means the analyzer ran without
error, not that it verified the specific rule behind an existing finding
(a rule can be removed/disabled/not-loaded while the analyzer itself still
exits cleanly). Condition 1 above is superseded — see the amendment.

An analyzer that didn't run, wasn't applicable, was unavailable, failed,
or timed out gives **no evidence** the underlying issue is gone — findings
tied to it are left completely untouched by reconciliation, regardless of
how many scans pass. This is the difference between "confirmed absent"
and "not checked," and collapsing them would make auto-resolution
actively dangerous (silently hiding real, unfixed issues the moment a
scanner has a bad day). `App\Audit\Findings\Ingestion\FindingReconciler`
enforces this exactly; `scan_analyzer_executions` is what makes it
possible to know, per scan, per analyzer, whether "absence" can be trusted.

**Suppressed statuses do not un-suppress themselves.** A finding marked
`ACCEPTED_RISK`/`FALSE_POSITIVE`/`IGNORED` keeps that status even if
re-observed in a later scan (a new occurrence is still recorded — the
evidence isn't hidden — but the status is left alone). Only `RESOLVED`
reopens automatically on reappearance, because `RESOLVED` is a belief
("this went away") that new evidence directly contradicts, whereas the
suppressed statuses are deliberate human judgments about a finding that
_is_ (or may still be) present — re-detecting it doesn't change that
judgment.

**Every transition is centralized** in
`App\Audit\Findings\Lifecycle\FindingLifecycleService` — the only code
path allowed to change a Finding's status, called by both manual
(human-triggered, future UI) and automatic (ingestion/reconciliation)
callers, so validation (a reason is required for
`ACCEPTED_RISK`/`FALSE_POSITIVE`/`IGNORED`) and history-writing never
diverge between the two.

### History, not full event sourcing

`finding_status_histories` is scoped to **identity/status events only**
(created, status changed, including auto-resolve/reopen) — an
append-only, immutable audit trail. A plain re-observation with no status
change is already captured by the corresponding `FindingOccurrence` row;
it does not also get a history entry. This keeps history meaningful
(every row is a real lifecycle event) without building full event
sourcing for a domain that doesn't need it yet.

### No `AnalyzerCapability`-style new category enum

`findings.category` reuses `App\Audit\Engine\Contracts\AnalyzerCategory`
directly (Phase 2) rather than a parallel `FindingCategory` enum, per the
explicit instruction to keep these aligned — a finding's category is
always inherited from the analyzer/rule that produced it.

## Consequences

- Every future real analyzer (Phase 4+) must produce a
  `App\Audit\Findings\FindingCandidate` (rule id, analyzer id, category,
  severity, confidence, location, evidence) rather than writing directly
  to `Finding`/`FindingOccurrence` — this is the seam Phase 4's
  normalization step is expected to target.
- Phase 8 (history/comparison) can build NEW/RESOLVED/UNCHANGED/REGRESSED
  reporting as a read over `findings`/`finding_occurrences`/
  `finding_status_histories` without new tables — REGRESSED in particular
  is just "history event where previous_status=resolved and
  new_status=open", already recorded.
- A real secret scanner is still future work; the redaction applied to
  persisted evidence (`App\Audit\Findings\Redaction\EvidenceRedactor`) is
  explicitly defense-in-depth against obvious patterns, not a replacement
  for one.
- Multi-connection database evidence, richer fingerprint algorithms (v2+),
  a human-friendly sequential display key (e.g. `SEC-0042`) alongside the
  opaque `public_id`, and per-analyzer timeout/fail-fast policy remain
  explicitly deferred — none of them block this phase's core guarantee:
  findings persist correctly across scans without ever being silently
  lost, duplicated, or falsely resolved.

## Amendment (Phase 3.1): Coverage-gated auto-resolution

### Context

Phase 3's rule — "auto-resolve when the finding's analyzer completed the
scan `Passed` and wasn't re-observed" — has a gap: `Passed` is a claim
about the analyzer's own execution health, not about which rules it
actually verified. An analyzer can exit cleanly while the specific rule
behind an existing finding was removed, disabled, or simply not loaded
into that run's ruleset. Under the Phase 3 rule alone, that finding would
be silently (and wrongly) auto-resolved the moment the analyzer next
passes — a false "fixed" that hides a real, still-present issue.
`ExecutionStatus::Passed` and "this finding's rule was verified" are
different claims and must not be conflated.

### Decision

- **A new claim, `AnalyzerCoverage`** (`App\Audit\Engine\Execution\AnalyzerCoverage`,
  with `CoverageMode`: `Unknown | Explicit | Full`), lives on
  `AnalyzerResult` (Phase 2) — an analyzer's own declaration of what its
  execution actually verified, entirely independent of `status`.
    - `Unknown` (the default whenever an analyzer says nothing) never
      authorizes resolution.
    - `Explicit` lists the exact rule ids verified this run; only findings
      whose `rule_id` is in that list are eligible.
    - `Full` declares the run covered its entire relevant domain — **never**
      inferred from `status === Passed`; only ever set by an explicit
      analyzer declaration.
    - `rulesetVersion` (optional, on `AnalyzerCoverage`) is provenance only
      — it is never consulted to authorize or block a resolution. A version
      bump with unchanged coverage changes nothing; coverage changing
      (even under an unchanged version) changes everything.
- **The auto-resolution rule is now:** a finding may be auto-resolved
  only when (1) it's `OPEN`/`CONFIRMED`, (2) its analyzer completed this
  scan `Passed`, (3) that execution's `AnalyzerCoverage.verifies(rule_id)`
  is true for the finding's `rule_id`, and (4) it received no new
  occurrence this scan. `App\Audit\Findings\Ingestion\FindingReconciler`
  enforces all four; coverage evaluation happens per analyzer execution,
  so a finding is only ever resolved by the execution belonging to its
  own `analyzer_id` — coverage never crosses analyzer boundaries.
- **Persisted alongside each `scan_analyzer_executions` row** (a nullable
  JSON `coverage` column, added via a new migration — the existing
  `scan_analyzer_executions` migration was not edited) so historical
  reconciliation has the same evidence a live scan would.
- **Belongs to the Audit Engine, not the Findings domain.** `coverage` is
  a property of `AnalyzerResult`/exposed via `AnalyzerExecution::coverage()`
  (Phase 2) — an analyzer declares it about its own run regardless of who
  consumes it. This avoids inverting the dependency (Findings does not
  reach into the Engine's internals; the Engine's own execution-result
  contract simply grew a field that Findings, among any future consumer,
  can read).
- **Existing fake analyzers were updated explicitly, not defaulted to
  permissive.** `AlwaysPassAnalyzer` (and any test relying on it for
  auto-resolution) now accepts an optional `AnalyzerCoverage`, defaulting
  to `Unknown` — tests demonstrating a real resolution must opt in
  explicitly to `Explicit`/`Full` coverage. Nothing was made to default to
  `Full` merely to keep old assertions green.

### Consequences

- Phase 4's first real analyzer must decide, per run, what coverage it
  can honestly declare — an analyzer that can't enumerate what it checked
  should report `Unknown` and accept that its findings won't auto-resolve
  until it can.
- `docs/auditing/findings-lifecycle.md`'s concurrency section was also
  corrected during this amendment: `lockForUpdate()` only locks a row that
  already exists; it cannot prevent two transactions that both observe
  "no matching Finding yet" from both attempting an insert. The database's
  unique constraint on `(project_id, fingerprint, fingerprint_version)` is
  the actual final guarantee against a duplicate — verified by a
  dedicated test (`FindingIngestionTest`).
- No new ADR was created — this amends ADR-0010 directly, since coverage
  is a refinement of the exact auto-resolution decision this ADR already
  owns, not an independent decision.
