# Manual Audit Guide — First Real Project Test

**Status: Phase 6.** This is the guide for running LaraDogs against a
**real** Laravel project for the first time, now that a first genuinely
useful (though still small and deliberately conservative) Laravel-aware
ruleset exists (see
[`../auditing/rules/security-rules.md`](../auditing/rules/security-rules.md),
[`../auditing/rules/quality-rules.md`](../auditing/rules/quality-rules.md),
and [`../auditing/rules/performance-rules.md`](../auditing/rules/performance-rules.md)).

Every command in this guide was actually run — against this repository's
own codebase and against the Docker image — before being written down.
None are aspirational.

## Prerequisites

- LaraDogs itself set up locally (see
  [`../development/setup.md`](../development/setup.md)) **or** the
  official Docker image built (see [Docker test](#docker-test) below).
- `composer` on LaraDogs' own `PATH` (for `--analyzer=composer-audit`).
- `npm` on LaraDogs' own `PATH` (for `--analyzer=npm-audit`).
- `semgrep` (>= `1.176.0`) on LaraDogs' own `PATH`, or set
  `LARADOGS_SEMGREP_BINARY` — for `--analyzer=semgrep`. Not required for
  Docker (already bundled — see [Docker test](#docker-test)).
- A target Laravel project on disk. **LaraDogs never needs write access to
  it** — see [No target mutation guarantee](#no-target-mutation-guarantee).

## Local test

```bash
# 1. Stack detection only (no scanners run, nothing persisted) — useful
#    as a first sanity check that LaraDogs sees the project correctly.
php artisan laradogs:inspect /path/to/your/laravel/project

# 2. Run every applicable analyzer.
php artisan laradogs:audit /path/to/your/laravel/project

# 3. Run just Semgrep (the Laravel-aware ruleset from this phase).
php artisan laradogs:audit /path/to/your/laravel/project --analyzer=semgrep

# 4. Machine-readable output, for scripting/piping into another tool.
php artisan laradogs:audit /path/to/your/laravel/project --json
```

`--analyzer=composer-audit` and `--analyzer=npm-audit` also work the same
way, each requiring the matching lockfile
(`composer.lock`/`package-lock.json` or `npm-shrinkwrap.json`) to be
applicable — see
[`../auditing/analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md)
and [`../auditing/analyzers/npm-audit.md`](../auditing/analyzers/npm-audit.md).

**A real, production Laravel application can genuinely take 10+ minutes
to scan.** The Semgrep analyzer's `timeout_seconds` (whole-scan timeout)
now defaults to **1800 seconds (30 minutes)**, calibrated from a real
first-run validation: a real, production Laravel app (908 first-party
PHP/Blade files) took ~767-864 seconds (~13-14 minutes) end-to-end — this
is **not** a bug, it's Semgrep's own per-file overhead when scanning an
explicit list of individual files (required for security — see
[`../auditing/analyzers/semgrep.md#performance`](../auditing/analyzers/semgrep.md#performance)
for the full investigation), confirmed to scale linearly at ~0.86 seconds
per first-party file, essentially independent of how many rules run. The
1800s default carries ~2x headroom over that measured worst case, wide
enough to absorb ordinary machine load rather than the original, tighter
35% margin. For an EVEN larger project, raise it further:

```bash
LARADOGS_SEMGREP_TIMEOUT_SECONDS=2400 php artisan laradogs:audit /path/to/your/laravel/project --analyzer=semgrep
```

If a scan does time out, `laradogs:audit` reports it explicitly
(`[timed_out]`), with an actionable message pointing at this exact env
var — it never reports a false-clean pass, never claims coverage, and
never auto-resolves anything on a timed-out run (fail-closed, unchanged
by this calibration).

## Docker test

Mount the target **read-only** (`:ro`) — LaraDogs never needs write access
to it, and this is the concrete way to prove that to yourself:

```bash
# From the LaraDogs repository root, with the image already built
# (docker compose build app) and a real APP_KEY set:
docker run --rm \
  -e APP_KEY="$(php artisan key:generate --show)" \
  -v "/path/to/your/laravel/project:/targets/project:ro" \
  laradogs-app:latest \
  php artisan laradogs:audit /targets/project
```

Add `--analyzer=semgrep` (or `composer-audit`/`npm-audit`) the same way as
the local command. See
[`../development/docker.md`](../development/docker.md) for what the image
bundles (Composer 2.10.3, Node 22/npm, Semgrep 1.176.0, all confirmed
present and working via `docker run --rm --entrypoint sh laradogs-app:latest
-c "composer --version && node --version && npm --version && semgrep --version"`).

## Expected output

Human-readable output shows, per executed analyzer: status
(`passed`/`failed`/`not_applicable`/`unavailable`/`timed_out`), a one-line
summary, and any diagnostics — then, if any `FindingCandidate`s were
produced, a `Findings (N)` section listing each one:

```
[passed] Semgrep (semgrep)
  2 finding(s) across 1 PHP file(s) scanned (coverage: explicit).

 INFO Findings (2).

[MEDIUM] laradogs.quality.debug.dd-call (quality, confidence: high)
  app/Http/Controllers/HomeController.php:12
  dd() halts execution and dumps variable state — left in code, it will
  break the request that reaches it in production. Remove before shipping.
```

Each finding shows: **severity** (color-coded), **rule id**,
**category**, **confidence**, **file:line**, and the **message**. `--json`
output additionally includes `description`, `recommendation`, `cwe`, and
`references` per finding — see
[`../auditing/analyzers/semgrep.md#cli`](../auditing/analyzers/semgrep.md#cli).

An analyzer reporting `not_applicable` (e.g. `npm-audit` against a
pure-PHP project with no `package.json`) or `unavailable` (e.g. a binary
not found on `PATH`) is normal, expected behavior for a project that
doesn't use that ecosystem — not an error.

## Known limitations (read before your first real test)

- **Scans of large real projects can take 10+ minutes** — ~0.86 seconds
  per first-party PHP/Blade file, confirmed to scale linearly and to be
  essentially independent of how many rules run. See the timeout guidance
  above and
  [`../auditing/analyzers/semgrep.md#performance`](../auditing/analyzers/semgrep.md#performance)
  for the full real-world investigation behind this number.
- **Only 12 rules exist** (3 dependency/quality proof-of-vertical rules
  from Phase 5, 9 Laravel-aware rules from Phase 6) — this is not a
  comprehensive Laravel security scanner. See
  [`../auditing/static-analysis.md`](../auditing/static-analysis.md) and
  [`../auditing/rules/security-rules.md`](../auditing/rules/security-rules.md)
  for exactly what is and isn't covered, and each rule's own documented
  false-positive/false-negative behavior.
- **Taint-mode security rules are intraprocedural only** — a tainted value
  passed through another function/method before reaching a sink is not
  tracked across that call boundary. Real vulnerabilities of that shape
  will not be found.
- **The Blade XSS rule is a textual heuristic**, not real dataflow — it
  only catches a direct request-input call written inside the
  `{!! !!}` block itself, not a tainted variable assigned earlier and
  echoed later by name.
- **No authorization-bypass detection** — deliberately not attempted this
  phase; a naive "controller method without `authorize()`" pattern was
  evaluated and rejected as too false-positive-prone (see
  [`../auditing/rules/security-rules.md`](../auditing/rules/security-rules.md)).
- **No N+1 / performance detection beyond one conservative signal**
  (`Model::all()`, Info severity, Low confidence — a review hotspot, never
  a confirmed bug).
- **`composer-audit` and `npm-audit` findings never auto-resolve** (no
  "rules executed" universe to declare coverage from) — only `semgrep`
  findings do, and only when the run was fully covered. See
  [`../auditing/findings-lifecycle.md`](../auditing/findings-lifecycle.md).
- **No suppression UX yet.** The underlying lifecycle already supports
  False Positive / Accepted Risk / Ignored statuses (see
  [`../auditing/suppressions.md`](../auditing/suppressions.md)), but there
  is no CLI or UI to change a finding's status yet — that is future work.
  `laradogs:audit` itself never persists a `Scan`/`Finding` at all (see
  below), so this limitation doesn't affect the manual-test flow described
  here regardless.
- **No dashboard, no MCP server, no Git monitoring, no CI/GitHub Action**
  — none of these exist yet for any analyzer.

## How to report a false positive

If a rule flags something you believe is not actually a problem (or a
rule is a genuine false NEGATIVE — code that should have been flagged but
wasn't), the most useful report includes:

1. The exact rule id (e.g. `laradogs.security.sql.tainted-raw-query`).
2. A minimal code snippet reproducing it (strip anything unrelated/
   sensitive — never share real secrets or proprietary business logic).
3. Whether you'd classify it as a false positive (flagged, shouldn't have
   been) or false negative (should have been flagged, wasn't).
4. If a false positive: what mitigation exists in your code that the rule
   didn't recognize (e.g. a validation pattern not in the rule's known
   sanitizer list — see that rule's own "Limitations" section in
   [`../auditing/rules/security-rules.md`](../auditing/rules/security-rules.md)
   for what's already known/documented).

There is no automated false-positive submission flow yet — report through
whatever channel the project is using for issues at the time (see
[`CONTRIBUTING.md`](../../CONTRIBUTING.md)).

## No target mutation guarantee

LaraDogs never writes to, executes anything from, or otherwise mutates
the audited project. This has been true since Phase 1 (Project Discovery)
and is verified, not merely claimed:

- Discovery only ever reads files as plain bytes/JSON — never `include`s,
  `require`s, or `eval`s anything from the target (`ADR-0008`).
- `composer-audit`/`npm-audit` never run `composer install`/`npm install`/
  `npm ci` — both audit directly from the lockfile, and both pass explicit
  flags (`--no-plugins --no-scripts` / `--ignore-scripts`) preventing any
  target-defined script from ever running as a side effect (`ADR-0011`).
- `semgrep` only ever reads target PHP source as pattern-matching input —
  proven with a dedicated fixture
  (`tests/Fixtures/semgrep/malicious-execution-project/app/Malicious.php`)
  containing a `system('touch .../SHOULD_NEVER_EXIST')` call at the top
  level: the marker file is never created, in both the automated test
  suite and a real-binary opt-in test.
- `laradogs:audit` itself **never persists anything** — no `Project`,
  `Scan`, or `Finding` row is ever created by this command; it prints one
  in-memory `AuditRunResult` and its normalized findings, then exits. The
  persisted path (`App\Audit\Findings\Ingestion\ScanRunner`) exists and is
  exercised by the automated test suite, but `laradogs:audit` does not use
  it — see
  [`../auditing/analyzers/composer-audit.md#cli`](../auditing/analyzers/composer-audit.md#cli).
- Real, filesystem-read-only-mount validation exists for all three
  analyzers (`composer-audit`, `npm-audit`, `semgrep`) — a real
  `:ro`-mounted target's contents were confirmed byte-for-byte unchanged
  after a real audit run, both locally (filesystem-permission-based) and
  in a real Docker container.

If you want the strongest possible guarantee for your own first test,
mount the project **read-only** (see [Docker test](#docker-test) above) —
LaraDogs does not need write access, and this makes that true at the
filesystem level, not just by code review.
