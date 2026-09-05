# ADR-0003: Finding Domain Model

## Status

Proposed — this ADR records the intended shape of the `Finding` entity for
future phases (Phase 3: Finding Domain + Persistence). **No `Finding` model,
migration, or table exists yet in this codebase.** It is written now so that
Phase 2/3 implementation has an agreed target instead of improvising the
schema mid-flight.

## Context

A finding is the atomic unit of everything LaraDogs reports: a security
issue, a bug, a dependency vulnerability, a quality smell. Competing tools
(SonarQube, Semgrep, GitHub Code Scanning) converge on a similar shape for
good reason — findings need to survive re-scans, be deduplicated across
scanners, and be triaged by a human without losing history. We studied
these tools' public documentation for concepts only (severity levels,
suppression states, fingerprinting) — no code, schema, or copy was taken
from them.

Two properties matter enough to call out explicitly:

1. **Severity and Confidence are different axes.** A scanner can be very
   sure ("Confidence: HIGH") that something is a low-impact style issue
   ("Severity: LOW"), or unsure ("Confidence: LOW") that something is
   catastrophic if true ("Severity: CRITICAL"). Collapsing these into one
   number loses information that matters for triage.
2. **Fingerprints cannot be purely line-based.** Line numbers shift on
   every commit; a fingerprint keyed on file+line alone would mark every
   finding as "new" the moment someone adds a blank line above it, making
   history and regression tracking useless.

## Decision

Model `Finding` with (at minimum) these fields in Phase 3:

- `id` (stable, internal) and a separately stable `fingerprint` (derived
  from rule ID + normalized code context, not raw line number, so it
  survives unrelated edits above the finding).
- `rule_id`, `category` (`SECURITY | BUG | PERFORMANCE | DEPENDENCY |
QUALITY | CONFIGURATION | TEST`), `severity` (`CRITICAL | HIGH | MEDIUM |
LOW | INFO`), `confidence` (tracked independently of severity).
- `title`, `description`, `impact`, `recommendation`, `references`.
- `source` (which scanner/rule engine produced it) and `scanner_version` /
  `rule_version`, so a finding is reproducible against the tool state that
  generated it.
- `file`, `line_start`, `line_end`, `code_snippet`, contextual code.
- `cwe`, `cve` where applicable.
- `first_seen`, `last_seen`.
- `status`: `OPEN | CONFIRMED | RESOLVED | ACCEPTED_RISK | FALSE_POSITIVE |
IGNORED` — findings never silently disappear; a status transition is
  always recorded (who/when/why), which implies status changes need their
  own history/audit trail, not just a mutable column.
- `metadata` (scanner-specific extra data, JSON).

Secrets are a special case: if a finding's evidence is a detected secret
(e.g. `AWS_SECRET_ACCESS_KEY`), the finding stores a **redacted** form
(`AKIA************92H`), never the full value, even internally.

## Consequences

- Every future scanner integration (Phase 4+) must normalize into this
  shape rather than each defining its own ad hoc finding format.
- Comparing two scans (Phase 8) becomes a diff over `fingerprint` +
  `status`, not over raw line numbers — this is only possible because the
  fingerprint strategy above is decided before the first scanner is
  written.
- This ADR does not fix the exact fingerprint algorithm (e.g. AST-based
  vs. normalized-text hashing) — that is an implementation detail for
  Phase 3 to resolve once real scanner output is available to test
  against. The constraint that it _not_ be line-number-only is the part
  being locked in now.
