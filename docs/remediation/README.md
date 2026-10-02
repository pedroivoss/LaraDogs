# Remediation Workflow (Phase 12 — guidance only)

**Status: Implemented (V1, guidance-only).** Plan schema version `1`.

LaraDogs already knows what is wrong and where. Phase 12 answers the next
question — **"what should a developer do to remediate this finding safely?"**
— with a deterministic, structured **remediation plan** for every finding,
shown in the Dashboard, printed by the CLI and returned to IDE/AI clients over
MCP.

```
Finding + persisted evidence + last-seen scan (profile, source) + gate result
                         |
                 FindingRemediationQuery        bounded, read-only loading
                         |
                 RemediationEvidence            plain value object
                         |
                 RemediationPlanner             PURE transformation
                         |
                 RemediationPlan                typed, bounded, versioned
            /            |             \
      Dashboard        CLI JSON        MCP tool
```

All three surfaces call the same `FindingRemediationService`; none of them
re-derives or reformats guidance in its own way.

## What remediation is

- **Guidance**: a recommended action, ordered steps, honest limitations,
  validation actions, safe references and warnings.
- **Deterministic**: rule-owned constants and templates, plus a few
  strictly validated facts from the finding. Same input, same plan. No model,
  no LLM, no external AI, no network.
- **A read model**: computed on request, **never persisted**. There is no
  remediation table, cache or stored copy that could go stale.
- **Current guidance for stored facts**: if LaraDogs improves the wording of a
  rule's advice in a later release, old findings show the new wording.
  Historical scan and Quality Gate facts remain immutable.

## What it is NOT

LaraDogs V1 does **not**: edit files, generate or apply patches, commit,
create branches or pull requests, create issues, run codemods, run
`composer update` / `npm update` / `npm audit fix`, run the target's tests or
commands, call any AI model, or offer a "fix everything" action. There is no
apply/edit button anywhere. The calling IDE/AI client may propose a change to
a human using **its own** capabilities; LaraDogs supplies facts and guidance.

`automation_level` is always `guidance_only`. The enum deliberately contains
no `PatchSuggestion`/`AutoFixable` case: a level that does not exist cannot be
pretended. See [Future work](#future-work).

## The plan (`schema_version: 1`)

`RemediationPlan::toArray()` is the single serialization (Dashboard prop, CLI
`--json`, MCP `remediation` object).

| Field                                 | Meaning                                                                                                                                           | Trust                     |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------- |
| `finding_id`, `project_id`, `rule_id` | Public ULIDs and the rule id.                                                                                                                     | LaraDogs                  |
| `automation_level`                    | Always `guidance_only`.                                                                                                                           | LaraDogs                  |
| `guidance_available`                  | `false` when no rule-specific guidance exists (truthful fallback; nothing is fabricated).                                                         | LaraDogs                  |
| `lifecycle`                           | `status`, `actionable`, `note` — never hides the finding's state.                                                                                 | LaraDogs                  |
| `guidance`                            | `source` (`rule_catalog`, `dependency_advisory`, `none`), `summary`, `recommended_action`, `steps[{order,text}]`, `limitations[]`.                | LaraDogs (static)         |
| `validation[]`                        | Structured actions (below).                                                                                                                       | LaraDogs                  |
| `references[]`                        | `{url}` — `https` only, validated, at most 10, never fetched.                                                                                     | validated data            |
| `warnings[]`                          | `{code, message}`, e.g. `source_changed`, `finding_resolved`, `no_rule_guidance`.                                                                 | LaraDogs                  |
| `source`                              | `state` + observed/current short revision (see below).                                                                                            | LaraDogs (derived)        |
| `quality_gate`                        | `impact` (`blocking`, `non_blocking`, `not_evaluated`, `undetermined`), `basis`, `gate_scan_id`, `evaluated_at`.                                  | LaraDogs (persisted)      |
| `context`                             | Framework/PHP version from the last-seen scan's profile, when known.                                                                              | validated                 |
| `dependency`                          | For Composer/npm advisories: `ecosystem`, `package`, `affected_versions`, `fixed_version`, `fix_available`, `fix_is_semver_major`, `advisory_id`. | validated                 |
| `finding`                             | Title, message, impact, category, severity, confidence, status, CWE/CVE — `content_trust: "untrusted_source_data"`.                               | **untrusted** (sanitized) |
| `evidence`                            | `location {path (project-relative), line_start, line_end}` and a bounded `snippet` — `content_trust: "untrusted_source_data"`.                    | **untrusted** (sanitized) |
| `provenance`                          | `guidance_basis`, `rule_version`, `analyzer_version` — informational only; never alters lifecycle.                                                | LaraDogs                  |

Bounds: steps ≤ 8 (400 chars each), validation ≤ 8, references ≤ 10, warnings
≤ 10, limitations ≤ 5, recommended action ≤ 1500, title ≤ 300, message ≤ 2000,
snippet ≤ 1500. There is no numeric risk score and no "fix confidence": the
plan reuses the finding's own `severity` and `confidence` as independent
dimensions.

## Rule-owned guidance

`App\Audit\Remediation\RuleRemediationCatalog` is the canonical, static and
trusted source of each LaraDogs-owned rule's `summary`, `action`, `steps` and
`limitations` — specific ("bind the value as a parameter") rather than
generic. The `action` paragraph is the bundled ruleset's own
`metadata.remediation` text (`resources/audit/semgrep/rules/laradogs-rules.yml`,
which Semgrep passes back and the ingestor persists as `findings.recommendation`).
Tests keep the catalog, `SemgrepRuleCatalog` and the YAML text in lockstep, so
a rule cannot ship without guidance and the two texts cannot drift.

The **persisted** `findings.recommendation` is deliberately **not** used to
build guidance: a database value is data, not a LaraDogs constant. It remains
visible where it always was (`laradogs.get_finding`) but never reaches the
trusted `guidance` fields, and guidance is only ever produced for the bundled
`semgrep` analyzer's rules — a finding from any other source that reuses a
LaraDogs rule id gets the honest "no guidance" fallback.

Covered rules: `dd()`, `var_dump()`, `ray()`, `eval()`, tainted raw SQL, Blade
raw output, tainted command execution, tainted filesystem path, tainted open
redirect, mass assignment via `->all()`, `APP_DEBUG` defaulting to true, and
unbounded `Model::all()`. Detection logic and rule ids are unchanged by this
phase. Heuristic (taint-based) rules carry an explicit limitation: confirm the
value really originates from user input before changing code, and mark a false
positive with a reason if it does not.

### Composer and npm advisories

Dependency guidance is **factual** and built only from evidence persisted at
scan time:

- **Composer** persists the package, advisory id, affected range and
  references. It does **not** persist a fixed version or the installed version,
  so the plan says so and never invents one ("choose a release outside the
  affected range after reviewing compatibility").
- **npm** persists whether a fix is available and, when npm reported it, the
  fixed version and whether it is semver-major. The plan surfaces exactly that,
  adds a `dependency_fix_major` warning for major bumps, a `dependency_no_fix`
  warning when npm reports no fix, and a note for transitive dependencies.
- Package names, ranges, versions and advisory ids are only interpolated into
  text after passing strict patterns; otherwise the plan says "the affected
  package".
- LaraDogs never runs `composer update`/`require`, `npm install`/`update`/`audit fix`,
  and performs no registry or Packagist lookup during remediation.

## Lifecycle-aware semantics

Guidance is available for **every** status; the plan states the status
honestly and flags non-active work:

| Status           | `actionable` | Presentation                                                       |
| ---------------- | ------------ | ------------------------------------------------------------------ |
| `open`           | yes          | Actionable guidance.                                               |
| `confirmed`      | yes          | Actionable guidance.                                               |
| `resolved`       | no           | Historical guidance; not active work (`finding_resolved` warning). |
| `false_positive` | no           | Reference only; marked false positive (`finding_false_positive`).  |
| `ignored`        | no           | Reference only; intentionally ignored (`finding_ignored`).         |
| `accepted_risk`  | no           | Reference only; risk accepted (`finding_accepted_risk`).           |

Requesting a plan never changes the finding or its history.

## Source provenance (Phase 9)

`source.state` compares the source the finding was **last observed on** (the
last-seen scan's persisted snapshot) with the **current** source (one call to
the existing bounded, read-only Git inspector) — by **commit**, so a renamed
branch on the same commit is the same code:

| State                   | Meaning                                                                                            | Warning                |
| ----------------------- | -------------------------------------------------------------------------------------------------- | ---------------------- |
| `same_revision`         | Same commit, clean tree — the reported location is still valid.                                    | —                      |
| `changed_since_finding` | Different commit (or repository state) — the location may have moved or vanished.                  | `source_changed`       |
| `dirty`                 | Same commit but uncommitted changes.                                                               | `source_dirty`         |
| `unavailable`           | The current source could not be inspected.                                                         | `source_unavailable`   |
| `not_versioned`         | Not a Git repository — no revision to compare.                                                     | `source_not_versioned` |
| `unknown`               | No comparable evidence (legacy scan without source metadata; audited on a dirty tree; no commits). | `source_unknown`       |

A stale or untrustworthy source **warns, it never blocks**: generic guidance is
always returned. Legacy scans are handled without reconstructing history.

## Quality Gate impact

`quality_gate.impact` is derived from the **persisted** gate result of the
project's newest terminal scan (the same one the Dashboard shows) — the gate is
**never re-evaluated** to answer:

- `blocking` — the finding is listed by a `Failed` rule of that result;
- `non_blocking` — a result exists and no `Failed` rule references it;
- `not_evaluated` — no gate result (gate disabled, no terminal scan, legacy scan);
- `undetermined` — a `Failed` rule's finding list was truncated (ids are capped
  per rule) and does not include this finding, so participation cannot be
  proven.

If a listed finding's status has since stopped counting towards the gate, a
`gate_result_predates_status` warning says the result is historical.

## Validation guidance

Every plan tells the developer how to verify the fix, but LaraDogs **never
executes** anything on the target:

- prose: review the change, run **your project's own test suite** yourself;
- `laradogs_tool` — `laradogs.run_project_audit` with `{project_id}` (MCP);
- `laradogs_command` — `laradogs:project:audit {project}` and, when a gate
  exists, `laradogs:project:gate {project}`.

Commands are structured (`command` + `arguments` map), contain no shell string
and no interpolation; target-project commands are prose only.

## Surfaces

**Dashboard — Finding Detail.** A _Remediation_ card replaces the old
"Recommendation" block and the unvalidated reference list: lifecycle note,
warnings, recommended action, steps, limitations, dependency facts,
validation, safe references, a _Guidance only_ badge, source-state and gate
badges. It renders as plain, escaped React text (no raw HTML, no Markdown, no
HTML-injection API); external links are `https`-only with
`rel="noopener noreferrer"`. There is **no** edit/apply button. Finding _lists_
carry no remediation text and do no per-row remediation work. The finding's
own raw, unvalidated `references` are never sent to the browser at all
(Phase 12.1) — `remediation.references` (`SafeReference`-filtered) is the
only reference data the page receives, so there is one public form, never a
raw one alongside it.

**CLI.** `php artisan laradogs:finding:remediation {finding} [--json]` (a
finding's public id). Human output is a formatted view of the plan; `--json`
writes pure JSON (`{"schema_version":1,"remediation":{…}}`) to stdout — public
ids, project-relative paths, sanitized evidence, no host path, secret or user
identity. Exit code `1` for an unknown id.

**MCP.** `laradogs.get_finding_remediation` (`finding_id`) — **read scope
only**, same authorization semantics as `get_finding`; no new scope. Result:
`{"schema_version":1,"remediation":{…plan…}}`; errors reuse the Phase 11
envelope (`finding_not_found`, `invalid_arguments`, `unauthenticated`,
`internal_error`). The tool's description and the server instructions are
static; source-derived text lives only under `finding` and `evidence`, tagged
`untrusted_source_data`. See [`../integrations/mcp.md`](../integrations/mcp.md).

### Intended IDE workflow (V1)

```
laradogs.get_finding
      ↓
laradogs.get_finding_remediation
      ↓
IDE / AI client proposes a change to a human        (client's own capability)
      ↓
developer edits the source and runs their tests
      ↓
laradogs.run_project_audit        (audit-scope token, Owner/Admin)
      ↓
laradogs.get_audit_status
      ↓
laradogs.get_scan_quality_gate
```

## Security boundary

- **Deterministic, no AI**: no model dependency, no LLM/HTTP client, nothing
  sent anywhere.
- **No target mutation**: the remediation namespace, its MCP tool and its CLI
  command contain no filesystem write, process execution, Git write, network or
  persistence-write primitive — enforced by an architectural test over the
  parsed token stream (`RemediationNoWriteGuardTest`), plus a purity test for
  the planner (no database, models, Git, container or config).
- **Untrusted evidence**: finding text is sanitized (secrets redacted, host
  paths masked, control characters stripped, lengths bounded) by the **one**
  shared `OutputSanitizer` — the same code MCP uses — is never evaluated,
  interpolated into a shell or template, or copied into a trusted field.
  Package names/versions are pattern-validated before use.
- **Path privacy**: only project-relative paths leave LaraDogs; a location that
  cannot be expressed safely is omitted.
- **Reference safety**: `https` only; `javascript:`, `data:`, `file:`, `http:`,
  protocol-relative and credentialed URLs are dropped; never fetched.
- **Least privilege**: MCP read scope is enough; nothing here needs `audit`.

## Known limitations

- Guidance covers the 12 LaraDogs-owned rules and Composer/npm advisories;
  anything else gets the truthful "no rule-specific guidance" fallback.
- Composer advisories carry no fixed version in persisted evidence; the
  installed version is not stored.
- Guidance is rule-level, not project-specific code analysis: it does not read
  other project files to "look smarter".
- Semgrep detection is heuristic (see each plan's limitations).
- Finding list rows show no "guidance available" indicator (deferred; it would
  be a per-row concern).
- Lifecycle changes remain a Dashboard action; there is no
  status-changing remediation tool.

## Future work

Deliberately **not built** and not promised: patch suggestions (a unified diff
implies assumptions about current source, syntax and replacement correctness),
auto-fix, pull-request generation, codemods and LLM-assisted explanations. If
any of these is ever pursued it needs its own design: a safe patch system with
proof of correctness, explicit human approval, a separate scope/actor/provenance
model and a fresh security review — and only after this guidance has proven
useful and stable. Until then `AutomationLevel` has exactly one case.
