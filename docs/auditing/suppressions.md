# Suppressions and Status Lifecycle

**Status: Implemented** (Phase 3 — the data model, lifecycle service, and
auto-resolution safety described here are real; see
[`findings-lifecycle.md`](findings-lifecycle.md) for the full mechanics).
**Planned:** a UI to act on this (Phase 7/8) and declarative inline
suppressions (e.g. `// laradogs-ignore`).

A finding's `status` records what a human (or, later, an authorized
automated policy) has decided about it. Findings never disappear silently
— a status change is always an explicit, recorded transition.

## Statuses

- `OPEN` — default state; detected and not yet triaged.
- `CONFIRMED` — a human has verified this is a real issue.
- `RESOLVED` — the underlying code was fixed; a later scan stopped
  detecting it.
- `ACCEPTED_RISK` — real, but knowingly not being fixed (with a
  justification recorded).
- `FALSE_POSITIVE` — not a real issue; the detection was wrong.
- `IGNORED` — deliberately excluded from attention without asserting it's
  a false positive (e.g. out of scope for this project).

`ACCEPTED_RISK`, `FALSE_POSITIVE`, and `IGNORED` all **require** a
justification string when set (`App\Audit\Findings\Lifecycle\FindingLifecycleService`
throws if none is given), so future audits/reports can explain _why_ a
CRITICAL finding isn't blocking anything. `OPEN`/`CONFIRMED`/`RESOLVED`
don't require one, though a reason can still be recorded for any
transition.

**Re-detecting a suppressed finding does not un-suppress it.** If a
finding marked `ACCEPTED_RISK`/`FALSE_POSITIVE`/`IGNORED` is observed
again in a later scan, a new occurrence is recorded (the evidence isn't
hidden) but its status is left exactly as it was — only a human (or a
future explicit policy) moves it out of a suppressed status. This is
deliberate: those three statuses are judgments about a finding that may
still be present; re-detecting it doesn't invalidate the judgment.

## Regression / reopening (implemented, Phase 3)

`RESOLVED` is the one status that **does** react automatically to
re-detection: if a `RESOLVED` finding's fingerprint is observed again,
`App\Audit\Findings\Ingestion\FindingIngestor` reopens it to `OPEN`
immediately, at ingestion time — not just as a later reporting step — and
records a history row (`previous_status=resolved`, `new_status=open`,
system-authored reason). There is no separate `REGRESSED` status; a
regression is exactly that history row, derivable whenever needed from
`previous_status=resolved && new_status=open`. See
[`findings-lifecycle.md`](findings-lifecycle.md#regression--reopening) and
[ADR-0010](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md).

## Comparison reporting (planned, Phase 8)

A dedicated **NEW / RESOLVED / UNCHANGED / REGRESSED diff view** between
two specific scans — for a dashboard/CI-gate-style report, distinct from
the lifecycle reopening above which already happens regardless of whether
anyone asks for a comparison — is Phase 8 scope. It would be a read over
`findings`/`finding_occurrences`/`finding_status_histories`, not a new data
model; all four values are already derivable from what Phase 3 persists
(`NEW`: `first_seen_scan_id` = the scan in question; `RESOLVED`/
`REGRESSED`: a `finding_status_histories` row scoped to that scan;
`UNCHANGED`: an occurrence exists with no accompanying status change).
