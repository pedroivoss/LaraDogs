# Severity

**Status: Implemented** (Phase 3, as `App\Audit\Findings\Severity`,
alongside the [`Finding`](findings.md) model).

Severity answers: **if this finding is real, how bad is it?** It is
independent of how sure LaraDogs is that the finding is real — see
[`confidence.md`](confidence.md) for that axis.

## Levels

- `CRITICAL` — directly exploitable / catastrophic if true (e.g. SQL
  injection, auth bypass, RCE-class issue).
- `HIGH` — serious impact, likely exploitable or high-cost if left unfixed.
- `MEDIUM` — real impact, but limited scope, harder to exploit, or
  requires specific conditions.
- `LOW` — minor impact; best-practice deviation with limited real-world
  consequence.
- `INFO` — informational; not a problem by itself, useful context.
- `UNKNOWN` — the finding is real, but the source did not report a
  magnitude for it (added in Phase 4: real Composer security advisories
  frequently have a `null` `severity` field upstream). Distinct from
  `INFO`, which means "not a problem" — `UNKNOWN` means "is a problem,
  magnitude not stated." Never assigned by LaraDogs guessing; only when
  the underlying data genuinely carries none.

Concrete severity assignment rules per rule/category are introduced
per-analyzer as real scanners are added. The first, `composer-audit`
(Phase 4), maps Composer's own `severity` string directly
(`critical`/`high`/`medium`/`low` → the matching level, anything else
including `null` → `UNKNOWN`) — see
[`analyzers/composer-audit.md`](analyzers/composer-audit.md#severity-and-confidence).
