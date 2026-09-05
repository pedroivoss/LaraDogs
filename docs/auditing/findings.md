# Findings

**Status: Planned** (Phase 3). No `Finding` model, migration, or table
exists yet. This documents the target shape from
[ADR-0003](../architecture/decisions/ADR-0003-finding-domain-model.md) in
prose; treat the ADR as the source of truth if the two ever diverge.

A finding is the atomic unit of everything LaraDogs reports.

## Fields (target)

| Field                                              | Notes                                                                                                                                          |
| -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                                               | Internal stable identifier.                                                                                                                    |
| `fingerprint`                                      | Derived from rule + normalized code context — **not** line number alone, so it survives unrelated edits. Used to track a finding across scans. |
| `rule_id`                                          | Which rule/check produced this.                                                                                                                |
| `category`                                         | `SECURITY \| BUG \| PERFORMANCE \| DEPENDENCY \| QUALITY \| CONFIGURATION \| TEST`.                                                            |
| `severity`                                         | See [`severity.md`](severity.md).                                                                                                              |
| `confidence`                                       | See [`confidence.md`](confidence.md) — independent of severity.                                                                                |
| `title`, `description`, `impact`, `recommendation` | Human-facing explanation.                                                                                                                      |
| `source`                                           | Which scanner/engine produced it.                                                                                                              |
| `scanner_version`, `rule_version`                  | For reproducibility against the tool state that generated the finding.                                                                         |
| `file`, `line_start`, `line_end`, `code_snippet`   | Location and evidence.                                                                                                                         |
| `cwe`, `cve`                                       | When applicable.                                                                                                                               |
| `references`                                       | Links to further reading.                                                                                                                      |
| `first_seen`, `last_seen`                          | Timestamps across scans.                                                                                                                       |
| `status`                                           | See [`suppressions.md`](suppressions.md).                                                                                                      |
| `metadata`                                         | Scanner-specific extra data.                                                                                                                   |

## Secrets are redacted, not stored

If a finding's evidence is a detected secret, only a redacted form is
persisted (e.g. `AKIA************92H`), never the full value — this
applies even to LaraDogs' own database, not just to what's displayed.

## Findings don't silently disappear

A finding that stops being reported by a scanner isn't deleted; its
`status`/`last_seen` reflect that it's no longer detected, so history stays
queryable ("was this ever found, and when did it stop?").
