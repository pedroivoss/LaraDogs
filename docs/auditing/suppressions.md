# Suppressions and Status Lifecycle

**Status: Planned** (Phase 3 for the data model, Phase 7/8 for the UI to
act on it).

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

`ACCEPTED_RISK` and `FALSE_POSITIVE` in particular are expected to require
a justification string when set by a human, so future audits/reports can
explain _why_ a CRITICAL finding isn't blocking anything.

## Regression detection

Comparing two scans should surface, per finding:

- `NEW` — didn't exist in the previous scan.
- `RESOLVED` — existed before, no longer detected.
- `UNCHANGED` — still detected, same status.
- `REGRESSED` — was `RESOLVED`/`ACCEPTED_RISK`/`FALSE_POSITIVE` and is
  detected again (e.g. a fix was reverted).

This depends on the fingerprint strategy in
[ADR-0003](../architecture/decisions/ADR-0003-finding-domain-model.md) and
the immutable-scan model in
[ADR-0005](../architecture/decisions/ADR-0005-persistence-and-deployment-profiles.md),
neither of which is implemented yet.
