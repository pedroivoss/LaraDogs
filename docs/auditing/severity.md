# Severity

**Status: Planned** (Phase 3, alongside the [`Finding`](findings.md) model).

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

Concrete severity assignment rules per rule/category are not defined yet —
they'll be introduced alongside the first real scanners and rules
(Phase 4+), not invented speculatively here.
