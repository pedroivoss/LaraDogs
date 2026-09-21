# Quality Gates & Policy Engine (Phase 8)

**Status: Implemented.** A Quality Gate answers one question about a
finished scan: _does this scan satisfy the project's policy?_ It is a
**policy/evaluation layer on top of facts LaraDogs already owns** (scans,
analyzer executions and their coverage, findings and their lifecycle) —
it never changes those facts to manufacture a result, and it is **not a
security score** (no number, no grade, no "A+").

```
Scan  +  Project policy (revision N)  +  finding state  +  executions/coverage
                              ↓
                     QualityGateEvaluator          (pure, deterministic)
                              ↓
              Passed  /  Failed  /  Indeterminate
```

The Audit Engine knows nothing about gates: it collects facts; a policy
layer reacts afterwards.

## Outcomes

| Outcome           | Meaning                                                                    |
| ----------------- | -------------------------------------------------------------------------- |
| **Passed**        | Every rule is satisfied **and** the evidence supporting it is trustworthy. |
| **Failed**        | At least one rule has a **proven** violation.                              |
| **Indeterminate** | LaraDogs lacks enough trustworthy evidence to assert compliance.           |

`Indeterminate` is not a warning and not "unknown": it exists because
_absence of evidence is not evidence of absence_. "No High finding" cannot
be called a pass when Semgrep timed out and never looked. Combination
across rules: **Failed > Indeterminate > Passed** (a proven violation is
never hidden by incomplete evidence elsewhere). A policy with no rules
never yields a Pass.

There is no "Passed" for a gate that is **disabled** or for a scan that was
never evaluated — those simply have no result, and the UI says so
("Quality Gate disabled" / "Not evaluated").

## Policy vs. result

- **Policy** (`project_quality_gates`): what should be true. One optional
  row per project; **no row = no gate**. Default is **disabled**, and
  nothing ever enables a gate automatically — no existing project can
  start failing because of an upgrade.
- **Result** (`quality_gate_results` + `quality_gate_rule_results`): what
  was observed for **one scan**, written once and **never updated**.

Policy edits and results are decoupled on purpose: each result stores the
policy **revision** and a **snapshot** of the rules it was judged against.
Changing or disabling the policy affects only _future_ scans; earlier
results stay exactly as they were (and stay visible after disabling).

### Revisions

Every _effective_ change (the enabled flag or any rule) increments
`revision` by exactly 1; saving an unchanged policy does not. The update
runs in a short transaction with a row lock (`lockForUpdate()`), so
concurrent saves cannot reuse a revision. Verified with 8 truly parallel
processes against MySQL 8.4 (revisions 2–9, no lost update). Documents are
compared in canonical form because MySQL's native `JSON` type reorders
object keys.

## Policy representation

A small, bounded, versioned JSON document that is **only** produced and
consumed through typed value objects (`QualityGatePolicy` and its rules) —
strict parsing, unknown keys/types rejected, numbers range-checked, lists
capped. There is **no expression language**: nothing in a policy is
evaluated as PHP, SQL or shell.

```json
{
    "schema": 1,
    "rules": [
        {
            "type": "laradogs.gate.max-open-findings",
            "limits": { "critical": 0, "high": 0 }
        },
        { "type": "laradogs.gate.no-new-severity", "min_severity": "high" },
        {
            "type": "laradogs.gate.analyzer-status",
            "analyzers": ["composer-audit", "semgrep"]
        },
        {
            "type": "laradogs.gate.analyzer-coverage",
            "requirements": { "semgrep": "explicit_or_full" }
        }
    ]
}
```

A limit of `0` is an enforced "none allowed"; an **absent** severity is not
enforced — the two are distinct (the form uses a blank field for "not
enforced").

## Rule catalog (V1)

Stable identifiers — safe for persistence, history, CLI JSON, and a future
API/CI/MCP. At most one rule of each type per policy; a rule produces one
result **per subject** (a severity or an analyzer).

### `laradogs.gate.max-open-findings`

For each enabled severity, the number of **current gate-eligible**
findings of exactly that severity must be `<= max`. `Unknown` (unstated
magnitude) is its own bucket — enforced only when configured, never folded
into another severity.

- count `> max` → **Failed** (proven, even if other evidence is incomplete);
- count `<= max` → **Passed** only if the audit is trustworthy, otherwise
  **Indeterminate** (see [Evidence gaps](#evidence-gaps)).

Boundary: `count == max` passes; `count > max` fails.

### `laradogs.gate.no-new-severity`

No **new or regressed** gate-eligible finding at or above `min_severity`
compared with the **baseline** (below). "New" is decided by the existing
`Finding` logical identity (the fingerprint) — never by titles, messages or
line numbers alone (a finding that merely moved lines is the same finding).

| Situation                                                              | Result                           |
| ---------------------------------------------------------------------- | -------------------------------- |
| No eligible baseline                                                   | **Indeterminate**                |
| Finding never seen before this scan                                    | new → **Failed**                 |
| Seen earlier, absent in baseline, **baseline verified its rule**       | regressed → **Failed**           |
| Seen earlier, absent in baseline, baseline did **not** verify its rule | cannot prove → **Indeterminate** |
| Nothing new, but the audit had an evidence gap                         | **Indeterminate**                |
| Nothing new and trustworthy audit                                      | **Passed**                       |

`regressed` is comparison metadata only — **no `REGRESSED` lifecycle status
exists** and the gate never persists one. Findings below the threshold are
ignored; `Unknown`-severity findings count as meeting **every** threshold
(fail closed).

### `laradogs.gate.analyzer-status`

Each listed analyzer must have **executed and Passed**. A statement about
the execution itself:

| Analyzer execution in the scan  | Result            |
| ------------------------------- | ----------------- |
| Passed                          | Passed            |
| Failed / TimedOut / Unavailable | **Failed**        |
| NotApplicable / Skipped         | **Indeterminate** |
| No execution recorded           | **Indeterminate** |

`NotApplicable` is deliberately _not_ a pass: if you require an analyzer
the project's stack does not call for (e.g. `npm-audit` on a project with
no `package.json`), the gate cannot show it passed — remove the
requirement or accept `Indeterminate`.

### `laradogs.gate.analyzer-coverage`

Each listed analyzer must have declared at least the required coverage
(`explicit_or_full`, or `full`). Coverage is a claim about the **evidence**,
so a missing claim is a **violation**, not indeterminacy:

| Analyzer execution                           | Result                                         |
| -------------------------------------------- | ---------------------------------------------- |
| Passed with coverage meeting the requirement | Passed                                         |
| Passed with `Unknown` (or too-weak) coverage | **Failed**                                     |
| Failed / TimedOut / Unavailable              | **Failed** (no coverage evidence was produced) |
| NotApplicable / Skipped / no execution       | **Indeterminate**                              |

`composer-audit` and `npm-audit` **always** report `Unknown` coverage and no
analyzer reports `Full`, so requiring known coverage from them fails by
design — LaraDogs does not claim coverage they cannot provide. The
Dashboard therefore only offers this rule for Semgrep.

## Which findings count

One central rule — `FindingStatus::countsTowardQualityGate()` — used by
every rule:

| Status           | Counts?                                                                                                                                          |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `open`           | yes                                                                                                                                              |
| `confirmed`      | yes                                                                                                                                              |
| `resolved`       | no                                                                                                                                               |
| `accepted_risk`  | **no** (V1: an operator deliberately accepted it, with a recorded reason; it stays untouched in the data — a future option could count it again) |
| `false_positive` | no                                                                                                                                               |
| `ignored`        | no                                                                                                                                               |

"Current" findings are the project's findings in a counting status
**at evaluation time** (right after the scan finished), so they include
findings of analyzers that did not run this scan and simply remain open.

## Severity ordering

`Severity::rank()` is the single trusted order: Critical > High > Medium >
Low > Info. **`Unknown` has no rank** — it means "a real problem, magnitude
not stated" and must never sort as harmless. `Severity::isAtOrAbove()`
treats `Unknown` as meeting every threshold.

## Baseline

The baseline for scan _S_ is the project's **previous `Completed` scan**
(highest id below _S_; scans of one project never overlap). A Queued,
Running or Failed scan is never a baseline — an incomplete scan cannot
justify "absent, therefore new/not new". If none exists, the
`no-new-severity` rule is Indeterminate.

## Evidence gaps

Conclusions that rest on the **absence** of findings (a count within its
limit; "nothing new") are only Passed when the audit is trustworthy: the
scan **completed** and no in-scope analyzer **Failed / TimedOut /
Unavailable / Skipped**. `NotApplicable` analyzers are not gaps (the
stack never called for them). Otherwise the rule is **Indeterminate**.

`Passed` status with `Unknown` coverage is **not** a gap for these count
rules — coverage requirements are their own explicit rule
(`analyzer-coverage`); add it for Semgrep if a partially-scanned Semgrep
run must not be trusted.

## When it runs

`ScanRecorder` dispatches `ScanFinished` when a scan reaches `Completed` or
`Failed` (never for `Queued`/`Running`); a listener runs
`EvaluateScanQualityGate`. That is the **only** path, so CLI
(`laradogs:project:audit`), Dashboard (queue worker) and scheduled audits
behave identically, with no per-trigger gate logic and no extra job (the
evaluation is a few bounded local queries). It runs after the scan's own
persistence has committed — never inside an analyzer or ingestion
transaction.

- **Gate disabled / no policy → no result** (never a fake Pass).
- **Failed scan** (worker crash, path vanished, stale reclaim) → evaluated
  with no executions → **Indeterminate** for anything that needs evidence,
  never Passed.
- **Idempotent:** one result per scan (unique `scan_id`); replays keep the
  first.
- **Isolated:** any evaluation error is reported and swallowed — a gate
  problem never breaks or rolls back a finished scan; the scan then has
  no result and every reader treats it as "not evaluated".
- Ad-hoc `laradogs:audit <path>` has no persisted project and is never
  gated.

## Results

`quality_gate_results`: outcome, policy revision + snapshot, baseline scan,
rule counts, evaluated-at (one row per scan).
`quality_gate_rule_results`: `rule_id`, `subject`, `outcome`, a short
`summary`, `observed`/`expected` (short strings), analyzer / severity, and a
**bounded** list (≤ 25) of finding public ids for navigation. Evidence is
bounded on purpose — full findings stay in `findings` /
`finding_occurrences`. Portable: plain string outcomes (no vendor enum),
plain JSON columns, no raw SQL beyond a constant `count(*)`.

Existing (pre-Phase-8) scans get **no** result and are shown as "not
evaluated" — no outcome is ever invented retroactively.

## Dashboard

- **Project Detail → Quality Gate card**: the latest terminal scan's
  result (Passed / Failed — _N_ policy violations / Indeterminate — reason
  / Disabled / Not evaluated), separate from scan status, with a link to
  the rule results. Refreshed by the existing polling while an audit is
  active; while one runs it keeps showing the last terminal result and
  never pretends to have the running scan's.
- **Owner/Admin** edit the policy with structured controls (per-severity
  maximums, "fail on new findings at or above …", required analyzers,
  Semgrep coverage). **User** sees the same state read-only; a direct
  `PUT` is refused with a 404 (server-side, via the `staff` middleware).
- **Scan History**: a Gate column (icon + text, never colour alone;
  Indeterminate also has a dashed border). **Scan Detail**: policy
  revision, baseline note, and every rule result with observed/expected
  values and links to the offending findings.

The person who changed a policy or started a scan is never exposed by
these payloads (Owner privacy).

## CLI

```bash
docker compose exec app php artisan laradogs:project:gate <PROJECT_ID>            # latest terminal scan
docker compose exec app php artisan laradogs:project:gate <PROJECT_ID> --scan=<SCAN_ID>
docker compose exec app php artisan laradogs:project:gate <PROJECT_ID> --json
```

It **reads the immutable result** — it never re-evaluates, runs an
analyzer, or changes anything. `laradogs:project:audit` additionally prints
the gate outcome (and a `quality_gate` key in `--json`) but its **own exit
code is unchanged** (0 when the audit completed).

### CLI exit codes

**V1 contract** — intended for CI; treat as stable:

| Code | Meaning                                                                                                                 |
| ---- | ----------------------------------------------------------------------------------------------------------------------- |
| `0`  | **Passed**                                                                                                              |
| `1`  | **Failed**                                                                                                              |
| `2`  | **Indeterminate** (not enough trustworthy evidence — fail closed)                                                       |
| `3`  | **Operational error** (unknown project / scan, project has no completed or failed scan yet)                             |
| `4`  | **Not evaluated** (no result for that scan: gate disabled then, evaluation failed, or a scan from before Quality Gates) |

These are deliberately distinct from Laravel's generic codes so a job can
tell a policy failure from an infrastructure problem. **A CI job must treat
any non-zero code — including `2` and `4` — as "do not proceed"**: exit
`4` is never a pass.

### JSON

```json
{
    "project": "<PROJECT_ID>",
    "scan": "<SCAN_ID>",
    "gate": {
        "outcome": "failed",
        "policy_revision": 3,
        "evaluated_at": "2026-09-20T14:39:01+00:00",
        "baseline_scan": "<SCAN_ID or null>",
        "rules_total": 3,
        "rules_failed": 1,
        "rules_indeterminate": 1,
        "rules": [
            {
                "rule_id": "laradogs.gate.max-open-findings",
                "subject": "high",
                "outcome": "failed",
                "summary": "…",
                "observed": "1",
                "expected": "<= 0",
                "analyzer_id": null,
                "severity": "high",
                "finding_count": 1,
                "finding_ids": ["<FINDING_ID>"]
            }
        ]
    },
    "exit_code": 1
}
```

Errors and not-evaluated cases use the same envelope with `"gate": null`,
an `"error"` message and the matching `exit_code`. No host paths or
secrets are emitted.

## Operations

- No new Docker service: migrations run with the existing `app` entrypoint;
  evaluation happens wherever a scan finishes (`app` for CLI, `worker` for
  Dashboard/scheduled audits).
- Migrations are portable (SQLite / MySQL / MariaDB / PostgreSQL) and were
  validated on MySQL 8.4 including an **upgrade** from the pre-Phase-8
  schema with legacy data (unchanged, no retroactive results).

## Security

Owner/Admin-only mutation (server-side); policies are typed data, never
code; the evaluator and loader are read-only (no analyzers, no
filesystem, no network, no lifecycle changes); historical results are
immutable; queries are bounded aggregates (no per-scan / per-project
query loops on Dashboard pages).

## Known limitations (V1)

- Rules judge **project-wide** current findings; no per-analyzer or
  per-category scoping of `max-open-findings` yet.
- `accepted_risk` never counts (no "count accepted risks" option yet).
- One evaluation per scan at completion; re-evaluating an existing scan
  against a new policy is not offered (a future, explicit feature).
- "Projects failing Quality Gate" on the Dashboard home and a gate column on
  the project list are not built.
- A future scan-to-scan comparison **report** (NEW / RESOLVED / UNCHANGED
  view) is separate from the baseline comparison used internally here.
