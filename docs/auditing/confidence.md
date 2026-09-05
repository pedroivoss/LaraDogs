# Confidence

**Status: Planned** (Phase 3, alongside the [`Finding`](findings.md) model).

Confidence answers: **how sure is LaraDogs that this finding is a real
issue** (as opposed to a false positive)? It is tracked separately from
[`severity.md`](severity.md) so triage doesn't lose information by
collapsing "how bad" and "how sure" into one number.

## Why this matters

A finding can legitimately be:

- **Severity: CRITICAL, Confidence: LOW** — a heuristic flags something
  that _would_ be catastrophic if true (e.g. a possible SQL injection
  pattern), but the heuristic has a high false-positive rate and needs a
  human/tool with more context to confirm.
- **Severity: MEDIUM, Confidence: HIGH** — a well-understood, precisely
  detected issue (e.g. a specific known-vulnerable dependency version)
  whose real-world impact is moderate.

Sorting only by severity would bury the second behind a flood of unsure
CRITICAL heuristics; sorting only by confidence would bury genuinely
dangerous-but-uncertain findings behind confidently-detected trivia. Both
axes are needed for useful triage.

## Levels

`HIGH`, `MEDIUM`, `LOW` — exact per-rule confidence calibration is a
Phase 4+ concern, introduced alongside real scanners rather than defined
in the abstract here.
