# GitHub Integration (Phase 10)

**Status: implemented** (Check Run reporting on top of `laradogs:ci:audit`).
GitHub **consumes** LaraDogs results — it never defines them. All
GitHub-specific code lives behind one boundary,
`App\Integrations\GitHub`, and is never reachable from `AuditEngine`,
`RunProjectAudit`, `QualityGateEvaluator` or `GitRepositoryInspector`.
**Not implemented**: GitHub API browsing, webhooks, managed clones,
continuous monitoring, PR annotations, MCP. See
[`../ci/README.md`](../ci/README.md) for the CI command this reports on.

## Architecture

```
Scan + QualityGateResult  →  RecordGitHubCheckRun  →  GitHubApiClient  →  GitHub REST API
                                     ↑
                               GitHubContext (validated GITHUB_* env)
```

- **`GitHubContext`** — the only place LaraDogs reads `GITHUB_*`
  environment variables. Every field is strictly validated or `null`;
  nothing is ever used to build a shell command (LaraDogs has no shell
  execution at all). `GITHUB_API_URL`/`GITHUB_SERVER_URL` are read directly
  (falling back to `config('laradogs.github')`) because Actions itself sets
  them correctly on GitHub Enterprise Server — see
  [GitHub Enterprise Server](#github-enterprise-server).
- **`GitHubApiClient`** — the only code that calls the GitHub REST API. A
  thin wrapper over Laravel's own HTTP client (no new SDK dependency).
- **`RecordGitHubCheckRun`** — the only code that connects the audit/gate
  domain to GitHub. Consumes an already-finished Scan and its (possibly
  absent) Quality Gate result; never evaluates a gate, never runs an
  analyzer, never changes either.
- **`github_check_reports`** — one small, bounded, portable table: at most
  one row per Scan, holding only what GitHub itself returned (its check run
  id, its own URL, the conclusion LaraDogs sent). Never a response blob,
  never a token, and never part of the Finding/Scan/Quality Gate domain —
  deleting this table changes nothing about what LaraDogs observed or
  decided, only whether GitHub was told.

## Authentication / token model

- The token is read **only** from the `GITHUB_TOKEN` environment variable
  — **never** a CLI argument (which would appear in `ps`/shell history on a
  shared runner).
- Sent **only** in the `Authorization: Bearer <token>` HTTP header — never
  embedded in a URL (`https://TOKEN@github.com/...` is never constructed
  anywhere in this codebase).
- **Never persisted** (no column for it anywhere), **never logged**, and
  never present in the CI JSON envelope, an exception message, or a log
  line — verified by dedicated tests using a fake token.

### An important GitHub API constraint

GitHub's own documentation states: **write access to the Checks API is only
available to GitHub Apps** — a classic or fine-grained Personal Access
Token, or an OAuth app token, **cannot** create a Check Run (expect
`403 Forbidden`, surfaced here as the `forbidden` reason). Inside GitHub
Actions, the built-in `GITHUB_TOKEN` **is** a GitHub App installation token
(scoped to that job, expiring at the end of the run), so it works — this is
the intended and only supported credential for V1. A self-hosted or
external caller needs a real GitHub App installation token instead of a
personal token.

## Required permissions

Minimum GitHub Actions workflow permissions:

```yaml
permissions:
    contents: read
    checks: write
```

`pull-requests: read` is **not** required by anything this phase builds.
Never request `contents: write` or an admin scope for this integration.

## GitHub API client

- Laravel's own HTTP client (`Illuminate\Http\Client`) — no new dependency.
- `Accept: application/vnd.github+json`, a pinned `X-GitHub-Api-Version`
  (`config('laradogs.github.api_version')`, default `2022-11-28` — GitHub's
  long-established stable version, the one used when the header is omitted
  entirely; bump deliberately, never float), and an explicit `User-Agent`
  (GitHub requires one).
- A short, dedicated timeout (`config('laradogs.ci.github_report_timeout_seconds')`,
  default 10s) — separate from analyzer timeouts, so a stuck GitHub request
  can never look like a stuck audit.
- **No automatic retry of the `POST`.** A create is not safely idempotent
  to retry blindly: a timeout does not tell the caller whether GitHub
  already created the resource before the connection dropped, and a blind
  retry risks a duplicate Check Run. A rate limit (`403`/`429`) is
  recognized and never retried either — see
  [Rate limits](#rate-limits-and-output-bounds).
- The response body is size-checked (`Content-Length`, 1 MB) before being
  parsed as JSON.

## Check Run lifecycle

**One Check Run is created already `completed`**, not a
`queued → in_progress → completed` sequence. This was a deliberate V1
simplification: `laradogs:ci:audit` runs the whole audit synchronously, so
by the time GitHub reporting happens the result is already known — there is
no genuine "in progress" phase to report separately, and reporting one
would add a failure state to reconcile (an orphaned `in_progress` Check Run
if the process crashed before completing it). GitHub Actions' own job UI
already shows the workflow step as running.

## Outcome mapping

| LaraDogs state                                     | GitHub `conclusion` | Why                                                                                                                                                                                                                                                                                                          |
| -------------------------------------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Gate **Passed**                                    | `success`           |                                                                                                                                                                                                                                                                                                              |
| Gate **Failed**                                    | `failure`           |                                                                                                                                                                                                                                                                                                              |
| Gate **Indeterminate**                             | `action_required`   | **Not** `neutral`: `neutral` reads as "ran, no opinion" and some branch-protection/UI paths do not visibly block on it — that would contradict the fail-closed rule that Indeterminate must never look like a pass. `action_required` is a non-success conclusion GitHub itself frames as needing attention. |
| Operational error (bad revision, audit failure, …) | `failure`           | Never silently green.                                                                                                                                                                                                                                                                                        |
| Gate **disabled** / not evaluated                  | `neutral`           | A genuine "no opinion" — the operator disabled the gate, so LaraDogs correctly asserts nothing either way.                                                                                                                                                                                                   |

Allowed values researched directly from GitHub's Checks API reference
(`success`, `failure`, `neutral`, `cancelled`, `skipped`, `timed_out`,
`action_required`); `cancelled`/`timed_out`/`stale` describe states GitHub
itself assigns to runs it manages and are never applicable to a Check Run
LaraDogs always creates already `completed`.

## Check Run summary / evidence bounds

The `output.summary` includes: the scan id and a short commit prefix, a
source-integrity note when relevant, the gate outcome and rule counts, each
**non-Passed** rule's summary line, an analyzer-failure count if any, and
(only when `config('laradogs.public_url')` is set) a link back to the Scan
in LaraDogs' own dashboard. **No findings are dumped into the Check Run** —
GitHub's own API imposes real limits and file/line mapping from a Check Run
annotation would be unreliable; see
[Finding annotations](#finding-annotations). The summary is bounded to
`config('laradogs.github.summary_max_length')` (default 4000 characters,
chosen defensively — GitHub's own documentation does not state an exact
`output.summary`/`output.text` character limit) and truncated with a marker
when it would exceed that.

## Rate limits and output bounds

`GITHUB_TOKEN` in GitHub Actions is limited to **1,000 requests per hour
per repository** (15,000 on Enterprise Cloud) — GitHub returns `403` or
`429` with `x-ratelimit-remaining`/`x-ratelimit-reset`/`retry-after`
headers when exceeded. LaraDogs makes **at most one** API call per CI run
(one Check Run create), so this is never a practical concern by volume; the
client still recognizes both signals (`429`, or `403` with
`x-ratelimit-remaining: 0`) and reports `rate_limited` rather than retrying.

## Failure isolation

**A GitHub reporting failure can never rewrite audit/gate facts.**
`RecordGitHubCheckRun::record()` never throws — every failure (network
error, timeout, `4xx`/`5xx`, malformed response) becomes a
`GitHubReportResult` with `reported: false` and a `reason`. The CI
command's own exit code is computed **before** `--github-report` is even
considered, and reporting never changes it: an audit that Passed still
exits `0` even if GitHub was completely unreachable. Verified by tests that
assert the Scan and Quality Gate rows are byte-identical before and after a
simulated `500` from GitHub.

## Idempotency / rerun behavior

`github_check_reports.scan_id` is unique. A retried `--github-report`
invocation for the **same** Scan finds the existing row and returns it
(`reason: already_reported`) without a second HTTP call — no duplicate
Check Run. A CI **rerun** (a new workflow attempt) produces a genuinely new
`Scan` (every audit is an immutable new row, by LaraDogs' own invariant), so
it naturally gets its own new Check Run with its own `external_id`
(`Scan::public_id`) — this is the correct, expected GitHub behavior (a
rerun commonly produces a fresh check for the same commit) and needs no
special handling.

## Runner topology

A safe V1 topology (see [`../ci/README.md`](../ci/README.md) for the full
architecture decision):

```
GitHub Actions
    ↓
self-hosted runner, workspace reachable under the LaraDogs /projects mount
    ↓
docker compose exec app php artisan laradogs:ci:audit ... --github-report --json
    ↓
registered Project, operator-owned Quality Gate policy
    ↓
audit + gate  →  GitHub Check Run
```

This avoids a public remote-execution endpoint entirely: the LaraDogs
instance is never reachable FROM GitHub, only the other way around. See the
example workflow: [`examples/github-actions-self-hosted.yml`](examples/github-actions-self-hosted.yml)
(a template for the AUDITED repository's own `.github/workflows/`, not part
of LaraDogs' own CI).

**GitHub-hosted runners** were not validated in this phase (Docker,
Semgrep's Python virtualenv, and LaraDogs' own database would all need to
exist inside that ephemeral runner) — this document does not claim support
for that topology; see [`../ci/README.md#limitations`](../ci/README.md#limitations).

### Fork PR security

**Critical.** GitHub does **not** give a normal `pull_request`-triggered
workflow the same secret access as a trusted branch: `secrets.GITHUB_TOKEN`
in a fork PR's `pull_request` workflow is read-only by default and cannot
create Check Runs. Do **not** switch to `pull_request_target` to work
around this while still checking out the fork's untrusted head — that
pattern (privileged token + untrusted checkout) is a well-known way to leak
secrets or let a fork-authored change run with elevated permissions, and
this documentation explicitly does not recommend it. LaraDogs itself never
executes the target project's code (see
[No target execution](#no-target-execution)), but the **runner's own**
security still depends entirely on how the workflow is written. A
self-hosted runner used for this integration must not be exposed to
arbitrary public fork PRs without a trust boundary the operator has
explicitly reviewed (ephemeral/isolated runners, or restricting the
workflow to trusted branches only).

### No target execution

Consuming CI's result never weakens LaraDogs' existing invariant: it never
executes the target application's own code. No `composer`/`npm` scripts
from the target, no `artisan` from the target, no target test suite — only
LaraDogs' own analyzers, exactly as before this phase.

## Pull request semantics

For the `pull_request` event, GitHub's default `actions/checkout` checks
out an **ephemeral merge commit** (the PR merged into its base branch), not
the PR's head commit — and `GITHUB_SHA` in that case is that merge commit's
SHA, **not** `github.event.pull_request.head.sha`. LaraDogs audits whatever
is actually on disk, so with the default checkout, `GITHUB_SHA` correctly
identifies what was audited (self-consistent), but the Check Run then
attaches to that ephemeral merge commit rather than the PR's real head —
GitHub's own UI correlates a PR's "Checks" list with the **head** commit,
so a check reported against the merge commit may not show where a reviewer
expects it.

**Recommendation:** for a `pull_request` workflow, explicitly check out and
audit the PR's real head commit:

```yaml
- uses: actions/checkout@v4
  with:
      ref: ${{ github.event.pull_request.head.sha }}

- run: |
      docker compose exec -T app php artisan laradogs:ci:audit /projects/<repo> \
        --expected-revision="${{ github.event.pull_request.head.sha }}" \
        --github-report --json
```

`--expected-revision` always overrides the `GITHUB_SHA` auto-default (see
[`../ci/README.md#revision-verification`](../ci/README.md#revision-verification)),
so this keeps "audited commit == reported commit" true. LaraDogs does not
auto-detect `github.event.pull_request.head.sha` from the Actions event
JSON itself in V1 — it must come from the workflow, explicitly.

## GitHub Enterprise Server

`GITHUB_API_URL`/`GITHUB_SERVER_URL` are read directly from the
environment — GitHub Actions sets these automatically to the correct GHES
URLs, so GHES support falls out without any LaraDogs-side per-instance
configuration. Outside Actions, `config('laradogs.github.api_url')` /
`server_url` are the fallback. Not independently validated against a real
GHES instance in this phase.

## Finding annotations

**Not implemented in V1**, deliberately: GitHub's annotation API has real
limits, and mapping a finding's file/line reliably (especially for a
`pull_request`'s diff-relative positions) is its own non-trivial problem.
A future phase may add a small, bounded set of top violations as
annotations; V1 keeps the Check Run summary as the only surface.

## Security review

- No target code execution (unchanged invariant).
- No public arbitrary audit endpoint (`laradogs:ci:audit` is only ever
  invoked locally/by an operator's own CI job).
- Exact revision verification — see
  [`../ci/README.md#revision-verification`](../ci/README.md#revision-verification).
- Token never logged, persisted, or present in any JSON output.
- Least-privilege permissions (`contents: read`, `checks: write` only).
- No `pull_request_target` + untrusted-checkout pattern recommended
  anywhere in this documentation.
- GitHub context (`GITHUB_REPOSITORY`, `GITHUB_SHA`, `GITHUB_SERVER_URL`,
  `GITHUB_API_URL`) is strictly validated — malformed/hostile values become
  `null`/rejected, never partially trusted, never used to build a shell
  command or an unvalidated URL.
- API requests are bounded (short timeout, capped response size, no
  automatic retry of a non-idempotent create).
- No secret in the CI JSON envelope, the persisted `github_check_reports`
  row, or the Quality Gate/Scan domain.
- Source integrity cannot be overridden by this adapter — it only reads
  `Scan`/`QualityGateResult`, never re-derives or second-guesses them.
- A Check-reporting failure cannot corrupt the audit/gate result (see
  [Failure isolation](#failure-isolation)).
- Owner privacy is unchanged — nothing about the acting user is ever sent
  to GitHub.
