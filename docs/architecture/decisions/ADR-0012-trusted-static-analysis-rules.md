# ADR-0012: Trusted Static Analysis Rules (Rule Source and Ignore-Policy Trust)

## Status

Accepted (Phase 5).

## Context

Phase 5 introduces LaraDogs' first static analyzer, Semgrep
(`App\Audit\Analyzers\Semgrep\SemgrepAnalyzer` — see
[`../../auditing/analyzers/semgrep.md`](../../auditing/analyzers/semgrep.md)).
Unlike `composer audit`/`npm audit` (Phase 4/4.2), which query a fixed
subcommand against a lockfile with no configurable "which checks run"
surface, a static analyzer's entire value comes from an explicit,
swappable set of rules — which raises a security question none of the
prior process-execution work (ADR-0011) had to answer: **who gets to
decide which rules execute against the audited project?**

Semgrep, like most static analysis tools, supports discovering its own
configuration from the project being scanned: a `.semgrep.yml`/
`.semgrep.yaml` file in the target repository, or `--config auto`, which
Semgrep's own CLI help text confirms "will log in to the Semgrep Registry
with your project URL" and fetch rules from Semgrep's remote Registry.
Neither of these can be allowed to determine what LaraDogs actually checks
— an untrusted, possibly hostile, target project must never be able to
choose (or worse, disable) the rules audited against it.

A second, related, empirically-discovered risk: Semgrep, again like most
static analysis tools, respects target-controlled ignore files
(`.semgrepignore`, and by default `.gitignore` for git-tracked-file
filtering) when given a directory as its scan target. This was verified,
not merely suspected, by direct reproduction: a fixture project's
`.semgrepignore` listing one specific, genuinely-vulnerable PHP file
caused a real `semgrep scan .` invocation to report **zero** results for
that file — it simply vanished from both `results` and `paths.scanned`,
with no error or warning signaling anything was omitted. The same file,
scanned via an **explicit path argument** instead of a directory, was
found immediately, with `.semgrepignore` having no effect at all on that
invocation.

Both of these are variations on the same underlying problem: a target
project has a real, working mechanism to influence what LaraDogs looks
at, and if left unmitigated, an adversarial (or merely differently-configured)
target could make a real vulnerability invisible to an audit while the
audit itself reports a clean pass.

## Decision

- **Rules executed by LaraDogs come from exactly three trusted sources,
  and never from the audited repository by default:**
    1. Rules LaraDogs itself bundles (`resources/audit/semgrep/rules/` —
       see `App\Audit\Analyzers\Semgrep\SemgrepRuleCatalog` and
       [`../../auditing/rules.md`](../../auditing/rules.md)).
    2. Rules explicitly configured by the LaraDogs operator (a future
       `config('laradogs.semgrep.*')` extension point, not yet built beyond
       the binary/timeout/byte-limit settings that exist today).
    3. Future trusted rule packs, distributed and vetted by LaraDogs itself
       — not yet built.
- **A target's own `.semgrep.yml`/`.semgrep.yaml` is never read,
  referenced, or auto-discovered.** `SemgrepAnalyzer` always passes an
  explicit `--config <bundled-rules-file>` — there is no code path where a
  target-controlled config file could be substituted or merged in.
- **`--config auto` (and any `p/...`/`r/...` remote Semgrep Registry
  reference) is never used.** LaraDogs V1 requires Semgrep CE/local CLI
  only: no Semgrep account, no API key, no login, no dependency on the
  Semgrep AppSec Platform/Cloud. Every scan works fully offline (the
  automated test suite proves this — no test requires network access to
  pass; the opt-in real-binary tests, gated by
  `LARADOGS_TEST_REAL_SEMGREP=1`, also stay fully local).
- **Targets are passed as an explicit, LaraDogs-collected file list —
  never a directory.** This is the concrete mitigation for the
  `.semgrepignore`/`.gitignore` bypass risk described above, verified
  empirically to work: `App\Audit\Analyzers\Semgrep\SemgrepTargetCollector`
  performs LaraDogs' own bounded, read-only directory walk (mirroring
  `App\Audit\Discovery\Filesystem\ProjectFilesystem`'s realpath-containment
  philosophy from ADR-0008) and hands Semgrep the resulting list of
  absolute file paths directly as scan-target arguments. Semgrep's own
  ignore-file consultation logic is never invoked in a way that could hide
  a file LaraDogs intended to scan.
- **The exclusion policy that DOES apply (`vendor/`, `node_modules/`,
  `storage/`, `bootstrap/cache/`, `public/build/`, `dist/`, `coverage/`,
  `.git/`) is LaraDogs' own, hardcoded in `SemgrepTargetCollector` —
  never derived from the target's `.gitignore`.** This is a deliberate,
  separate decision from the ignore-bypass mitigation above: LaraDogs
  does not want to analyze third-party/build-artifact directories as
  first-party code, but that decision must belong to LaraDogs/the
  operator, not be inherited from whatever the target happens to
  `.gitignore` (which could, in principle, list first-party source too).
- **Symlinks are rejected outright by `SemgrepTargetCollector`** (checked
  via `is_link()` before ever calling `realpath()`), independent of and in
  addition to Semgrep's own native symlink-scan-root protection (verified
  empirically: Semgrep itself refuses to scan a symlink target, surfacing
  a JSON `errors` entry rather than silently following or skipping it).
  Two independent layers, deliberately: LaraDogs' own walker should never
  offer Semgrep a symlink in the first place, both as defense in depth and
  to avoid the resulting noisy error entirely.
- **Bundled rule files are validated at execution time by Semgrep itself,
  fail-closed.** An invalid rule YAML file does not produce a false-clean
  scan — verified empirically (exit code `7`, a populated `errors` array,
  `results: []`) — and `SemgrepAnalyzer::run()` gates on
  `ProcessResult::successful()` before ever trusting output, so a broken
  bundled rule file surfaces as `ExecutionStatus::Failed`, never a clean
  pass with silently-fewer rules than intended.
- **Telemetry is always disabled** (`--metrics=off` plus
  `SEMGREP_SEND_METRICS=off`), and Semgrep's own login/API-token
  environment variable (`SEMGREP_APP_TOKEN`, confirmed by reading the
  installed CLI's own `semgrep/app/auth.py` source) is never forwarded to
  the subprocess — it is simply never added to
  `config('laradogs.process.env_allowlist')`. `SEMGREP_SETTINGS_FILE` is
  always forced to a LaraDogs-controlled path (confirmed against the
  installed CLI's own `semgrep/settings.py` resolution order), so a
  developer's own real `~/.semgrep/settings.yaml` — which may carry a
  stored login/anonymous id — is never read by this subprocess.

## Consequences

- A target project cannot supply, substitute, or influence which Semgrep
  rules execute against it, under any code path that exists today.
- A target project cannot hide a file from analysis via `.semgrepignore`
  or `.gitignore` — proven, not merely argued, by
  `tests/Feature/Audit/Analyzers/Semgrep/SemgrepAuditRealBinaryTest.php`'s
  `ignore-bypass-project` fixture against the real Semgrep binary.
- LaraDogs' own directory-exclusion policy (`vendor/`, `node_modules/`,
  etc.) is centralized in exactly one place
  (`SemgrepTargetCollector::DEFAULT_EXCLUDED_DIRECTORIES`) and is provably
  independent of the target's own `.gitignore` content.
- This ADR's trust model is written narrowly around Semgrep's own
  mechanisms (`.semgrep.yml`, `--config auto`, `.semgrepignore`); a future
  second static-analysis engine (a different SAST tool, a linter with its
  own remote-config or ignore-file conventions) would need its own
  research into whatever equivalent mechanisms it exposes — this ADR does
  not claim its specific mitigations (an explicit file list, a bare-filename
  `--config`) generalize automatically to a differently-shaped tool,
  though the underlying PRINCIPLE (the audited project never chooses its
  own rules or hides files from LaraDogs) is expected to hold for any
  future analyzer.
- Comprehensive rule libraries, remote/community rule packs, and an
  operator-facing "add your own rules" configuration surface remain future
  work — this ADR fixes the trust boundary those features will need to
  respect, not their full design.
