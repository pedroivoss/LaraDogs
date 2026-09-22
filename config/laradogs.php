<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LaraDogs Version
    |--------------------------------------------------------------------------
    |
    | Recorded on every Scan so a finding is reproducible against the
    | application state that produced it. LaraDogs has no release/tagging
    | scheme yet (pre-1.0, no scanners exist), so this is a placeholder
    | constant rather than a resolved git tag/composer version — the Scan
    | column exists now so history stays meaningful once real versioning
    | exists, without a schema change.
    |
    */

    'version' => env('LARADOGS_VERSION', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Public URL (Phase 10)
    |--------------------------------------------------------------------------
    |
    | An externally reachable base URL for THIS instance, used only to build
    | a link a GitHub Check Run's summary can point back to (e.g. the Scan
    | Detail page). Deliberately separate from `APP_URL` (which is very
    | often `http://localhost:...` for a self-hosted instance and would be
    | a meaningless link inside a GitHub Check) — `null` by default, and a
    | link is omitted entirely rather than ever showing a localhost URL as
    | if it were reachable. See docs/integrations/github.md.
    |
    */

    'public_url' => env('LARADOGS_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Process Execution
    |--------------------------------------------------------------------------
    |
    | Defaults for App\Audit\Engine\Process\ProcessRunner implementations.
    | `env_allowlist` is the fixed set of environment variable NAMES that
    | are ever allowed to reach a child process spawned on LaraDogs' own
    | behalf (e.g. to run `composer audit`) — every other variable LaraDogs
    | itself inherited (including anything from the TARGET's .env, which
    | LaraDogs never reads anyway, but also LaraDogs' own DB_PASSWORD,
    | API keys, etc.) is explicitly suppressed. See
    | docs/development/process-execution.md and ADR-0011.
    |
    */

    'process' => [
        'timeout_seconds' => (int) env('LARADOGS_PROCESS_TIMEOUT_SECONDS', 30),
        'max_output_bytes' => (int) env('LARADOGS_PROCESS_MAX_OUTPUT_BYTES', 5_000_000),
        'env_allowlist' => [
            'PATH',
            'HOME',
            'COMPOSER_HOME',
            'COMPOSER_CACHE_DIR',
            'SSL_CERT_FILE',
            'SSL_CERT_DIR',
            'HTTP_PROXY',
            'HTTPS_PROXY',
            'NO_PROXY',
            'http_proxy',
            'https_proxy',
            'no_proxy',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Git source inspection (Phase 9)
    |--------------------------------------------------------------------------
    |
    | LaraDogs OBSERVES a mounted repository's local Git state (commit,
    | branch, dirty flag, sanitized origin URL) — it never fetches, pulls,
    | pushes, checks out or contacts a remote. The target repository is
    | untrusted: every command is chosen by LaraDogs, argv-only, run with an
    | explicit minimal environment (see App\Audit\Source\Git\GitRepositoryInspector
    | and docs/git/README.md).
    |
    | `budget_seconds` is the TOTAL wall-clock budget for one inspection
    | (a handful of metadata commands — rev-parse/config/status/cat-file,
    | O(index), never history), deliberately far below Semgrep's timeout: a
    | healthy repository answers in milliseconds, and an unresponsive one
    | (network bind mount, huge working tree) must not stall a page load or
    | an audit. On expiry the state is reported as unavailable, never guessed.
    |
    | `max_output_bytes` caps each command's stdout/stderr; `home` is a
    | LaraDogs-controlled (never created, never read) HOME so no host
    | credentials or `~/.gitconfig` can be inherited.
    |
    */

    'git' => [
        'binary' => env('LARADOGS_GIT_BINARY', 'git'),
        'budget_seconds' => (int) env('LARADOGS_GIT_BUDGET_SECONDS', 10),
        'max_output_bytes' => (int) env('LARADOGS_GIT_MAX_OUTPUT_BYTES', 65_536),
        'home' => env('LARADOGS_GIT_HOME', storage_path('app/laradogs/git-home')),
    ],

    /*
    |--------------------------------------------------------------------------
    | CI (Phase 10)
    |--------------------------------------------------------------------------
    |
    | `laradogs:ci:audit`'s own limits — kept separate from the interactive
    | Dashboard/CLI ones so a stuck HTTP request to GitHub can never look
    | like a stuck audit.
    |
    */

    'ci' => [
        'github_report_timeout_seconds' => (int) env('LARADOGS_CI_GITHUB_TIMEOUT_SECONDS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub integration (Phase 10)
    |--------------------------------------------------------------------------
    |
    | GitHub CONSUMES LaraDogs results — it never defines them (see
    | docs/integrations/github.md and App\Integrations\GitHub). `api_url`/
    | `server_url` default to github.com but App\Integrations\GitHub\GitHubContext
    | prefers the GITHUB_API_URL/GITHUB_SERVER_URL environment variables
    | when present — GitHub Actions sets those automatically to the correct
    | values on GitHub Enterprise Server, so GHES support falls out of that
    | without any LaraDogs-side per-instance configuration; these two keys
    | are only the fallback for running OUTSIDE Actions.
    |
    | `api_version` is the `X-GitHub-Api-Version` header LaraDogs sends on
    | every request — pinned to GitHub's long-established stable version
    | (the one used when the header is omitted at all) rather than a newer
    | one, so a GitHub-side version rollout can never silently change the
    | request/response shape LaraDogs was built against; bump deliberately,
    | the same philosophy as SEMGREP_VERSION/COMPOSER_VERSION in Dockerfile.
    |
    */

    'github' => [
        'api_url' => env('LARADOGS_GITHUB_API_URL', 'https://api.github.com'),
        'server_url' => env('LARADOGS_GITHUB_SERVER_URL', 'https://github.com'),
        'api_version' => env('LARADOGS_GITHUB_API_VERSION', '2022-11-28'),
        'check_name' => env('LARADOGS_GITHUB_CHECK_NAME', 'LaraDogs Quality Gate'),
        'user_agent' => env('LARADOGS_GITHUB_USER_AGENT', 'LaraDogs/'.env('LARADOGS_VERSION', 'dev')),
        // Bounded so a pathological gate summary can never approach
        // GitHub's own (much larger, undocumented-exact) output limits —
        // see docs/integrations/github.md#output-bounds.
        'summary_max_length' => (int) env('LARADOGS_GITHUB_SUMMARY_MAX_LENGTH', 4000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Composer Audit Analyzer
    |--------------------------------------------------------------------------
    |
    | `binary` overrides automatic resolution (see ComposerBinaryResolver) —
    | it must point at an executable LaraDogs itself trusts, never anything
    | read from a target project. Left null, LaraDogs looks for `composer`
    | on its OWN PATH via Symfony's ExecutableFinder.
    |
    */

    'composer' => [
        'binary' => env('LARADOGS_COMPOSER_BINARY'),
        'timeout_seconds' => (int) env('LARADOGS_COMPOSER_TIMEOUT_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Npm Audit Analyzer
    |--------------------------------------------------------------------------
    |
    | `binary` overrides automatic resolution (see NpmBinaryResolver) —
    | never `./node_modules/.bin/npm` from a target, never a path read from
    | the target's own package.json. Left null, LaraDogs looks for `npm` on
    | its OWN PATH via Symfony's ExecutableFinder.
    |
    | `registry` is always passed as an explicit `--registry=` flag (the
    | highest-precedence form of npm config) so a target project's own
    | `.npmrc` registry override can never redirect audit queries to a
    | server it controls — verified empirically, see
    | docs/auditing/analyzers/npm-audit.md.
    |
    | `userconfig_path`/`cache_path` are always forced (never merely
    | forwarded) into the child process as NPM_CONFIG_USERCONFIG/
    | NPM_CONFIG_CACHE — neither path needs to exist ahead of time (npm
    | treats a missing per-user config file as "no per-user config", and
    | creates its cache directory on demand) — so a developer's own real
    | $HOME/.npmrc (which may carry registry auth tokens) is never read,
    | and npm's cache never lands inside the target or LaraDogs' own code.
    |
    | `proxy`/`https_proxy` (Phase 4.2.1): left null, `--proxy=false
    | --https-proxy=false` is always passed explicitly — verified
    | empirically that a target's own `.npmrc` `proxy=`/`https-proxy=`
    | would otherwise route the (correctly `--registry=`-pinned) audit
    | request through a server the target controls, defeating the
    | registry pin. If an operator genuinely needs LaraDogs itself to
    | reach the registry through a real, trusted proxy, set these here —
    | never left to whatever LaraDogs' own environment or the target's
    | `.npmrc` happens to set.
    |
    */

    'npm' => [
        'binary' => env('LARADOGS_NPM_BINARY'),
        'timeout_seconds' => (int) env('LARADOGS_NPM_TIMEOUT_SECONDS', 30),
        'registry' => env('LARADOGS_NPM_REGISTRY', 'https://registry.npmjs.org'),
        'userconfig_path' => env('LARADOGS_NPM_USERCONFIG_PATH', storage_path('app/laradogs/empty.npmrc')),
        'cache_path' => env('LARADOGS_NPM_CACHE_PATH', storage_path('app/laradogs/npm-cache')),
        'proxy' => env('LARADOGS_NPM_PROXY'),
        'https_proxy' => env('LARADOGS_NPM_HTTPS_PROXY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semgrep Analyzer
    |--------------------------------------------------------------------------
    |
    | `binary` overrides automatic resolution (see SemgrepBinaryResolver) —
    | never a binary living inside the target's own `.venv`/`node_modules`/
    | `vendor`, never a path read from any target project file. Left null,
    | LaraDogs looks for `semgrep` on its OWN PATH via Symfony's
    | ExecutableFinder.
    |
    | `timeout_seconds` and `per_file_timeout_seconds` are TWO DIFFERENT
    | timeout concepts — never confuse them:
    |
    |   A. `per_file_timeout_seconds` -> Semgrep's own `--timeout`: how long
    |      a SINGLE rule may spend on a SINGLE file before Semgrep itself
    |      aborts just that (rule, file) pair (recorded as a `Timeout` entry
    |      in that run's `errors[]`, which is exactly what makes
    |      SemgrepCoverageEvaluator fall back to Unknown coverage — see
    |      docs/auditing/analyzers/semgrep.md#coverage). A timed-out file
    |      does NOT stop the rest of the scan.
    |   B. `timeout_seconds` -> the WHOLE-PROCESS external timeout, enforced
    |      by SymfonyProcessRunner (Symfony Process's own `setTimeout()`).
    |      If the ENTIRE `semgrep scan` invocation (every rule against every
    |      collected file) has not finished by this deadline, the whole
    |      subprocess is killed and `SemgrepAnalyzer::run()` reports
    |      ExecutionStatus::TimedOut (fail-closed: no findings trusted, no
    |      coverage claimed — see `docs/auditing/analyzers/semgrep.md`).
    |
    | `timeout_seconds`'s default (1800s = 30 minutes; originally 1200s, see
    | below) is NOT arbitrary — it is calibrated from a real measurement
    | (Phase 6 real-world validation, 2026-09-08): a real, production-sized
    | Laravel application
    | (908 first-party PHP/Blade files after LaraDogs' own vendor/
    | node_modules/storage/etc. exclusions) took ~767-784 seconds
    | (~0.86s/file) for a full scan with the entire bundled ruleset —
    | confirmed, empirically, to scale LINEARLY with the number of target
    | FILES, essentially independent of rule count (a 3-rule subset and the
    | full 12-rule set both took ~13 minutes against the same 908 files).
    | Root cause (see docs/auditing/analyzers/semgrep.md#performance): when
    | Semgrep is given hundreds of separate file paths as individual
    | scanning-root arguments (LaraDogs' own explicit-file-list strategy,
    | which is REQUIRED for security — see below), it pays a fixed ~0.86s
    | of its own internal per-root overhead for EACH one; this is NOT a
    | LaraDogs inefficiency and is NOT reduced by simpler/fewer rules.
    | 1800s (raised from an original 1200s during Phase 6.1's rule-precision
    | refinement, 2026-09-08/09) gives a real 900-file-scale project ~2x
    | headroom over the measured worst case, while remaining a firm, finite
    | ceiling that still eventually kills a hostile or degenerate target
    | rather than hanging indefinitely. Root-caused before raising, per the
    | same methodology as the original 1200s calibration above: after
    | Phase 6.1 rewrote several rules' sink patterns as fixed-arity
    | `pattern-either` alternatives (to fix real false positives — see
    | docs/auditing/rules/security-rules.md), one full real-world audit run
    | timed out at 1200s; a clean, isolated re-measurement of the exact same
    | invocation (semgrep subprocess only, no other work running
    | concurrently) came back at ~864s — essentially unchanged from the
    | pre-Phase-6.1 ~767-828s, i.e. the additional pattern-either
    | alternatives did NOT meaningfully increase Semgrep's own per-file
    | cost. The 1200s timeout was almost certainly exhausted by ordinary
    | machine load (other concurrent work on the same machine at the time),
    | not a ruleset regression — but since real audits do run on shared,
    | loaded machines, headroom was widened accordingly rather than left
    | at the original, now-uncomfortably-tight 35% margin. A significantly
    | larger project (or a heavily loaded machine) may still need this
    | raised further via `LARADOGS_SEMGREP_TIMEOUT_SECONDS` — this is
    | LaraDogs' own env var, read from LaraDogs' own `.env`/environment,
    | never the target's.
    |
    | A directory-based scan (letting Semgrep discover files itself) was
    | measured to be ~200x faster (~3s for the same real project) — but was
    | REJECTED as an alternative: verified, live, against this exact real
    | project, that the target's own `.semgrepignore` silently hid 264 of
    | 908 real first-party files from such a scan (the same bypass Phase 5/
    | ADR-0012 already closed by adopting the explicit-file-list strategy
    | in the first place). A further experiment (copying LaraDogs-selected
    | files into a fresh, target-`.semgrepignore`-free synthetic directory)
    | was also fast (~3s) but uncovered a NEW, not-yet-fully-characterized
    | gap: Semgrep has its own BUILT-IN default ignore patterns (e.g.
    | common `tests/` subpaths) that silently excluded ~105 files even with
    | zero `.semgrepignore` present at all. Neither faster alternative is
    | adopted this phase — the explicit-file-list strategy's slower but
    | PROVEN-COMPLETE (zero silent exclusions, verified) behavior is kept.
    | This trade-off (correctness over speed) is deliberate — see
    | docs/auditing/analyzers/semgrep.md#performance for the full account.
    |
    | `max_target_bytes` is Semgrep's own `--max-target-bytes`
    | (Semgrep's own default is 1,000,000 bytes, passed explicitly for the
    | same clarity reason — verified empirically that a file over this
    | limit is silently skipped unless `--verbose` is also passed, which
    | SemgrepAnalyzer always does; see docs/auditing/analyzers/semgrep.md).
    |
    | `settings_path` is always forced into the child process as
    | SEMGREP_SETTINGS_FILE (verified against the installed CLI's own
    | `semgrep/settings.py` resolution order: `$SEMGREP_SETTINGS_FILE` ||
    | `$XDG_CONFIG_HOME/semgrep/settings.yaml` || `~/.semgrep/settings.yaml`)
    | — never needs to exist ahead of time, mirroring the same pattern
    | already used for NPM_CONFIG_USERCONFIG/COMPOSER_HOME: a developer's
    | own real `~/.semgrep/settings.yaml` (which may carry a stored
    | login/anonymous id) is never read by this subprocess.
    |
    */

    'semgrep' => [
        'binary' => env('LARADOGS_SEMGREP_BINARY'),
        'timeout_seconds' => (int) env('LARADOGS_SEMGREP_TIMEOUT_SECONDS', 1800),
        'per_file_timeout_seconds' => (int) env('LARADOGS_SEMGREP_PER_FILE_TIMEOUT_SECONDS', 5),
        'max_target_bytes' => (int) env('LARADOGS_SEMGREP_MAX_TARGET_BYTES', 1_000_000),
        'settings_path' => env('LARADOGS_SEMGREP_SETTINGS_PATH', storage_path('app/laradogs/semgrep-settings.yml')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persisted Projects (Phase 3.2 / Phase 7)
    |--------------------------------------------------------------------------
    |
    | `stale_scan_threshold_seconds` — see
    | App\Audit\Projects\StaleScanReclaimer. A `Scan` still `status=running`
    | after this many seconds since `started_at` is treated as abandoned
    | (the process that owned it crashed/was killed) rather than genuinely
    | still executing, and is reclaimed (marked `failed`) the next time
    | `RunProjectAudit::run()` is called for that project — closing the
    | Phase 3.2 known limitation ("a crashed process can leave a Scan stuck
    | Running indefinitely"). Never auto-resolves any Finding when
    | reclaiming — only the stale Scan row's own status changes.
    |
    | Default (3600s = 1 hour) is deliberately generous headroom over the
    | worst-case legitimate scan duration at CURRENT default timeouts: up
    | to `semgrep.timeout_seconds` (1800s) + the generic process timeout
    | for composer-audit/npm-audit (30s each, see `process.timeout_seconds`
    | above) is genuinely possible for a real project the analyzers run
    | sequentially against, i.e. ~31 minutes worst case — 3600s leaves
    | comfortable margin without being so long that a genuinely stuck scan
    | blocks new audits for that project for an unreasonable time. If you
    | raise `LARADOGS_SEMGREP_TIMEOUT_SECONDS` significantly, raise this
    | too.
    |
    */

    'projects' => [
        'stale_scan_threshold_seconds' => (int) env('LARADOGS_STALE_SCAN_THRESHOLD_SECONDS', 3600),

        /*
        |----------------------------------------------------------------
        | Queued-Scan Stale Threshold (Phase 7.1.4)
        |----------------------------------------------------------------
        |
        | Deliberately SEPARATE from `stale_scan_threshold_seconds` above
        | — see App\Audit\Projects\StaleScanReclaimer's own docblock for
        | the full reasoning. A short default (2 minutes): a healthy
        | queue worker picks up a `Queued` job within seconds, so
        | anything still `Queued` this long almost certainly means the
        | worker isn't running at all, not that it's merely busy.
        |
        */
        'queued_scan_stale_threshold_seconds' => (int) env('LARADOGS_QUEUED_SCAN_STALE_THRESHOLD_SECONDS', 120),

        /*
        |----------------------------------------------------------------
        | Scheduled Audit Time (Phase 7.1.4)
        |----------------------------------------------------------------
        |
        | The single instance-wide time-of-day (HH:MM, in
        | `config('app.timezone')`) every project's Daily/Weekly/Monthly
        | schedule runs at — see App\Audit\Projects\ProjectAuditScheduler's
        | own docblock for why this is intentionally NOT per-project in
        | V1 (arbitrary per-project times were explicitly out of scope).
        |
        */
        'scheduled_audit_time' => (string) env('LARADOGS_SCHEDULED_AUDIT_TIME', '02:00'),

        /*
        |----------------------------------------------------------------
        | Project Root (Phase 7.1.2)
        |----------------------------------------------------------------
        |
        | The single directory LaraDogs itself is allowed to look inside
        | when the Dashboard's "Add Project" picker lists candidate
        | directories or resolves one to register — see
        | App\Audit\Projects\ProjectDirectoryDiscovery. This is an
        | in-container path, always `/projects` in the documented Docker
        | profile (see docker-compose.yml's
        | `${LARADOGS_PROJECTS_PATH}:/projects:ro` bind mount — that HOST
        | env var is unrelated to this one and never read by the
        | application itself). Every candidate is realpath-resolved and
        | must remain within this root (containment, not merely a
        | string-prefix check) — the same defense ProjectFilesystem
        | already uses, applied here to reject symlink escapes and path
        | traversal before RegisterProject ever sees a path.
        |
        */
        'root' => env('LARADOGS_PROJECT_ROOT', '/projects'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Bootstrap (Phase 7.1.2, extended in Phase 7.1.3)
    |--------------------------------------------------------------------------
    |
    | Read ONLY by `php artisan laradogs:user:create-owner` (the primary
    | consumer as of Phase 7.1.3) and `laradogs:user:create-admin`, never
    | by any HTTP-reachable code path. Optional — both commands prompt
    | interactively (with hidden password input) for whichever of these
    | is not set, so this is purely a convenience for scripted/
    | non-interactive first-boot automation, never a shipped default
    | credential (see those commands' own docblocks and
    | docs/self-hosting.md's security principle: LaraDogs never
    | auto-creates a known account/password). Key names kept as
    | `LARADOGS_ADMIN_*`/`admin_bootstrap` from Phase 7.1.2 rather than
    | renamed to `owner`/`LARADOGS_OWNER_*` — an operator's existing
    | `.env` value keeps working unchanged, and the setting's real
    | meaning ("the account this CLI bootstrap creates") hasn't changed,
    | only which role it's now most commonly used for.
    |
    */

    'admin_bootstrap' => [
        'name' => env('LARADOGS_ADMIN_NAME'),
        'email' => env('LARADOGS_ADMIN_EMAIL'),
        'password' => env('LARADOGS_ADMIN_PASSWORD'),
    ],

];
