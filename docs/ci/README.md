# CI (Phase 10)

**Status: implemented.** `laradogs:ci:audit` is the one machine-oriented
entry point that answers _"can a repository revision be audited
automatically in CI, and can the result be reported back safely?"_ — for
any CI system, GitHub or not. GitHub-specific reporting (a Check Run) is a
thin, optional adapter on top; see
[`../integrations/github.md`](../integrations/github.md).

## Architecture: no second pipeline

`laradogs:ci:audit` invents nothing new. It converges on the exact same
pipeline every other trigger already uses:

```
path  →  RegisterProject (idempotent)  →  RunProjectAudit  →  persisted Scan  →  Quality Gate result
```

- **Project resolution** is `App\Audit\Projects\RegisterProject` — already
  idempotent by (realpath-resolved) path since Phase 3.2: calling it again
  for an already-registered directory just returns the existing Project,
  never a duplicate. CI never needs an interactive Dashboard step first.
- **The audit** is `App\Audit\Projects\RunProjectAudit::run()` — the exact
  class the Dashboard, the scheduler and `laradogs:project:audit` all call.
  No CI-specific analyzer orchestration exists anywhere.
- **The policy** is the **registered Project's own Quality Gate policy**
  (Phase 8) — an operator sets it once (Dashboard, Owner/Admin), and every
  CI run judges against whatever is currently configured. See
  [Policy source](#policy-source) below for why this codebase does not
  support a repository-committed `.laradogs.yml` policy file in V1.
- **The result** is read back exactly like `laradogs:project:gate` reads
  it — via a shared, byte-for-byte identical `gate` JSON shape
  (`App\Console\Commands\Support\GateResultCliPayload`), not a second,
  subtly different one.

Controllers/UI never execute Git or an analyzer directly, and this remains
true here: `laradogs:ci:audit` is a thin CLI adapter, same as every other
`laradogs:*` command.

## Execution topology (V1 decision)

Two topologies were possible:

- **A. Self-contained CI execution** — GitHub Actions checks out the
  target repository and runs LaraDogs itself, freshly, inside that job.
- **B. Hosted/self-hosted LaraDogs instance** — CI invokes a command
  against an ALREADY-RUNNING LaraDogs instance (the normal self-hosted
  Docker Compose stack) that has the target repository reachable under its
  existing `/projects:ro` mount.

**V1 chose B.** LaraDogs is a full Laravel application with a database, a
registered-Project/policy model, and a Semgrep virtualenv — packaging that
into a disposable, GitHub-hosted-runner-friendly unit would be a major
architecture change, explicitly out of scope for this phase. Model B reuses
the **already-validated** (Phase 7.1.4/Phase 9) self-hosted stack unchanged:
no new Docker Compose profile, no ephemeral CI database, no new host port.
A CI job runs, conceptually:

```
GitHub Actions
    ↓ (self-hosted runner, workspace under the LaraDogs /projects mount)
docker compose exec app php artisan laradogs:ci:audit /projects/<repo> --json
    ↓
registered Project, operator-owned policy, real persisted Scan + Gate result
    ↓ (optional)
GitHub Check Run
```

This is the same "possible hosted runner model" documented in
[`../integrations/github.md#runner-topology`](../integrations/github.md#runner-topology),
including its fork-PR security warnings. **Not built**: a public, arbitrary
remote-execution HTTP endpoint (`POST /api/audit` with attacker-supplied
path/command) — that is a distinct, dedicated security design this phase
deliberately does not open.

## The command

```
php artisan laradogs:ci:audit <path>
    [--expected-revision=<full-sha>]
    [--github-report]
    [--json]
```

`<path>` is the checked-out repository's directory. It is resolved via
`RegisterProject` exactly like `laradogs:project:add` would.

## Exit codes

The **same V1 contract** as `laradogs:project:gate` (Phase 8), extended
with this command's own operational cases. No sixth code was introduced —
these five already distinguish every case CI needs (a policy verdict vs. an
infrastructure/verification problem vs. "no verdict exists"):

| Code | Meaning                                                                                                                                                                                 |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `0`  | Audit completed, revision verified (if requested), gate **Passed**                                                                                                                      |
| `1`  | Gate **Failed**                                                                                                                                                                         |
| `2`  | Gate **Indeterminate**                                                                                                                                                                  |
| `3`  | **Operational error** — invalid project path, another audit already active, the audit itself did not complete, or (critically) the audited revision did not match `--expected-revision` |
| `4`  | **Not evaluated** — no Quality Gate policy is enabled for this project, or this scan predates Quality Gates                                                                             |

`laradogs:project:gate`'s own exit-code contract and JSON shape are
**unchanged** by this phase — `laradogs:ci:audit` is additive.

**Never silently maps Indeterminate to Passed**, and never silently treats
a disabled gate as a pass — those stay `2` and `4` respectively, distinct
from `0`.

## Final CI outcome

The exit code is NOT simply the Quality Gate outcome: an operational
condition discovered after the audit (the persisted revision does not match
the expected one) overrides a `Passed` gate. That precedence is decided in
exactly one place — `App\Audit\Ci\CiOutcome::resolve()`, a typed, generic
value (`Passed`/`Failed`/`Indeterminate`/`OperationalError`/`NotEvaluated`,
exit codes `0`/`1`/`2`/`3`/`4` — unchanged, no sixth code) that drives the
process exit code, the JSON `exit_code` and any GitHub Check conclusion
identically. Generic CI owns the outcome; adapters (GitHub) only map it.

## Revision verification

CI usually knows the exact commit it expects to audit. `--expected-revision`
(a full 40- or 64-character hex SHA) enables two checks:

1. **Before** the (possibly expensive) audit runs: a cheap, fresh
   `GitRepositoryInspector::inspect()` call compares the current source
   against the expectation. A mismatch fails fast (`3`) — **no Scan is
   created** for the wrong commit.
2. **After** the audit completes: the scan's own immutable
   `source_revision` (captured by `ScanRunner` **before analyzers ran** —
   see [`../git/README.md`](../git/README.md)) is compared again. A
   mismatch here still forces `3`, **even though a real Scan and Gate
   result now exist** — the JSON envelope still reports them, unmodified
   (for debugging), but `exit_code` is `3`, not whatever the gate computed,
   and a `--github-report` Check Run is `failure`, never `success` (see
   [Final CI outcome](#final-ci-outcome)).
   This is the authoritative check, closing the narrow window between
   check 1 and the audit's own internal snapshot; see
   `App\Console\Commands\Support\CiRevisionVerification` (one pure,
   directly unit-tested predicate, used by both checks).

**LaraDogs never audits commit A while reporting a verdict for commit B.**

**GITHUB_SHA fallback.** When `--expected-revision` is omitted and
`GITHUB_ACTIONS=true`, `GITHUB_SHA` is used automatically. An explicit
`--expected-revision` always wins. `GITHUB_SHA` is **not** auto-detected
from `pull_request` event payload fields (`github.event.pull_request.head.sha`)
— see [`../integrations/github.md#pull-request-semantics`](../integrations/github.md#pull-request-semantics)
for the exact, easy-to-get-wrong nuance and the recommended workflow
pattern.

## Dirty / source-integrity behavior

There is **no CI-specific dirty-worktree rule** — none is needed.
Phase 9.1's source-integrity semantics apply unchanged: a CI worktree that
is dirty when the audit begins, or that changes while it runs, cannot
support an absence-based Pass; the gate becomes Indeterminate (`2`), never
`0`. A **proven** violation is still `1`. The CI adapter has no way to
override this — see [`../git/README.md#source-integrity-truth-table`](../git/README.md#source-integrity-truth-table).

## Detached HEAD / PR checkout semantics

A detached HEAD is a normal, fully valid CI state (GitHub Actions checks
out this way by default) — the full commit SHA is canonical identity; no
branch is required or fabricated. See
[`../integrations/github.md#pull-request-semantics`](../integrations/github.md#pull-request-semantics)
for the `pull_request` event's specific "merge commit vs. PR head commit"
trap and how to avoid it.

## JSON contract

`--json` writes **only** the JSON envelope to **stdout** — nothing else,
ever, in that mode (verified by a real subprocess test: no framework
boilerplate, no ANSI, no stray line before or after the JSON). Human
progress notes go to **stderr** instead (`Resolving project…`,
`Running audit…`, …), so a CI log viewer still shows live progress during a
long analyzer pass while a downstream step can safely `| jq` stdout alone.

```json
{
    "project": { "id": "01H...", "name": "my-app" },
    "scan": {
        "id": "01H...",
        "status": "completed",
        "source": {
            "type": "git",
            "commit": "...",
            "branch": "main",
            "detached": false,
            "dirty": false,
            "consistent": true,
            "integrity_reason": null
        }
    },
    "ci": { "expected_revision": "...", "revision_verified": true },
    "gate": {
        "outcome": "passed",
        "policy_revision": 3,
        "evaluated_at": "2026-09-22T12:00:00+00:00",
        "baseline_scan": "01H...",
        "rules_total": 2,
        "rules_failed": 0,
        "rules_indeterminate": 0,
        "rules": [
            { "rule_id": "laradogs.gate.max-open-findings", "...": "..." }
        ]
    },
    "github": null,
    "exit_code": 0,
    "error": null
}
```

Never included: an absolute host filesystem path, credentials, the Owner's
identity, or unbounded content (finding evidence, full Semgrep output). The
`gate.rules[].finding_ids` list is the same bounded (≤ 25) list Phase 8
already enforces. `github` is `null` unless `--github-report` was passed —
see [`../integrations/github.md`](../integrations/github.md) for that
block's shape.

## Policy source

CI needs to know which policy applies. Three options exist in principle:
(A) the registered Project's own policy, (B) a policy file committed to the
repository, (C) CI flags. **V1 supports only (A).**

A repository-committed policy file (B) was deliberately **not** built: a
developer changing code in a pull request should not, in the same PR, also
be able to weaken the gate that judges that PR — policy stays
**operator-controlled**, edited only through the Dashboard by an Owner/Admin
(Phase 8), never by anything the audited repository itself contains.

## Generic (non-GitHub) CI

`laradogs:ci:audit` is independent of the GitHub **API** unless
`--github-report` is passed. Precisely:

Without `--github-report`:

- **no GitHub API request** is ever made and **no Check Run** is created;
- `GITHUB_TOKEN` is **not read for reporting**, no `Authorization` header
  exists, and it is not required;
- the validated **GitHub execution context** is still parsed
  (`GitHubContext`: `GITHUB_ACTIONS`, `GITHUB_SHA`, …) — under
  `GITHUB_ACTIONS=true`, `GITHUB_SHA` is used as the **default expected
  revision** (an explicit `--expected-revision` always wins). Nothing else is
  done with it, and every value is validated or discarded.

Outside GitHub Actions none of those variables has any effect. Any CI system
can use the command via exit code + JSON:

```yaml
# GitLab CI (illustrative)
audit:
    script:
        - docker compose exec -T app php artisan laradogs:ci:audit /projects/$CI_PROJECT_NAME --json | tee gate.json
    # LaraDogs' own exit code becomes the job's exit code.
```

```groovy
// Jenkins (illustrative)
sh 'docker compose exec -T app php artisan laradogs:ci:audit /projects/myapp --json > gate.json'
```

```sh
# Plain shell
docker compose exec -T app php artisan laradogs:ci:audit /projects/myapp --json > gate.json
case $? in
  0) echo "Quality Gate: Passed" ;;
  1) echo "Quality Gate: Failed"; exit 1 ;;
  2) echo "Quality Gate: Indeterminate"; exit 1 ;;
  *) echo "LaraDogs CI error"; exit 1 ;;
esac
```

No dedicated GitLab/Jenkins integration was built — these are illustrative
usage only.

## Limitations

- No public remote-execution HTTP API — CI must reach an operator-managed
  LaraDogs instance (self-hosted runner or equivalent), never the other way
  around.
- No repository-committed policy file — policy is always operator-owned.
- No self-contained "runs entirely inside a GitHub-hosted Action" packaging
  in V1 — see [Execution topology](#execution-topology-v1-decision).
- Hosted-repository features (GitHub API browsing, webhooks, managed
  clones, continuous "watch and re-audit" monitoring) are **not** part of
  this phase — see [`../roadmap/phases.md`](../roadmap/phases.md)'s Phase
  10 entry for what remains open there.
- Findings are never posted as GitHub PR annotations in V1 — see
  [`../integrations/github.md#finding-annotations`](../integrations/github.md#finding-annotations).
