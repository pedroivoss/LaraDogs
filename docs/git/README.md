# Git & repository integration (Phase 9)

**Status: implemented (local, read-only metadata only).**

LaraDogs is Git-aware: for every persisted audit it can answer _"what exact
Git source state did this scan inspect?"_ It does this by **observing the
local, mounted repository** — nothing more. There is no `fetch`, `pull`,
`push`, clone management, GitHub/GitLab API, webhook, CI workflow, checkout,
merge, rebase, reset or submodule update. Hosted-repository integration is
future work (see [Out of scope](#out-of-scope-and-future-work)).

## What is captured

A `GitSnapshot` (immutable value object, `App\Audit\Source\Git\GitSnapshot`):

| Field            | Meaning                                                                                 |
| ---------------- | --------------------------------------------------------------------------------------- |
| state            | `git` (repository), `none` (not a repository), `bare`, or `unavailable` (see below)     |
| commit SHA       | The **full** object id — the only persisted revision identity                           |
| branch           | The branch name; `null` on a detached HEAD (never fabricated from remote refs)          |
| detached         | `true` on a detached HEAD                                                               |
| dirty            | Whether the working tree differs from HEAD (see [dirty semantics](#dirty-semantics))    |
| commit timestamp | The commit's committer timestamp                                                        |
| commit subject   | The first line of the commit message (control characters stripped, ≤ 200 characters)    |
| origin remote    | The **sanitized** `remote.origin.url` (see [remote sanitization](#remote-sanitization)) |

Deliberately **not** collected: author or committer name/e-mail, file
listings, commit bodies, diffs, tags, other remotes, any absolute host path,
any credential. The short SHA (7 characters) is presentation only.

## Where it lives

- **One inspector.** `App\Audit\Source\Git\GitRepositoryInspector` is the
  only code that runs Git. The CLI (`laradogs:inspect`), the dashboard
  (Project Detail) and the audit runner (`ScanRunner`) all call it — there is
  no second probing implementation. Controllers and UI never execute Git.
- **Not in `ProjectProfile`.** A project can be a perfectly valid Laravel
  application without Git, and Git state is volatile (it changes with every
  commit), whereas the profile describes the detected stack. Source control
  is therefore a separate, optional snapshot, kept out of Project Discovery
  (which stays pure filesystem inspection).
- **Persisted on the `Scan`** (`scans.source_*` columns, added by an additive
  migration): `source_type`, `source_revision` (the full SHA — this column
  already existed and was always `null` before Phase 9), `source_branch`,
  `source_detached`, `source_dirty`, `source_commit_at`,
  `source_commit_subject`, `source_remote`, `source_consistent`,
  `source_integrity_reason`. Plain
  nullable string/boolean/timestamp columns: no vendor enum, no generated
  column, no JSON tricks (SQLite, MySQL/MariaDB and PostgreSQL alike).

## Repository detection

| Situation                               | Result                                                                                  |
| --------------------------------------- | --------------------------------------------------------------------------------------- |
| Not a Git repository                    | `none`. Audits work as before; a genuine non-Git target makes **no** integrity claim    |
| Normal working repository               | `git` with commit, branch, dirty                                                        |
| Detached HEAD                           | `git`, `detached = true`, branch `null`                                                 |
| Linked worktree (`.git` is a **file**)  | `git` — `.git` is never assumed to be a directory                                       |
| Initialized, no commits (unborn HEAD)   | `git`, no SHA, `dirty` computed, branch = the unborn branch name                        |
| Bare repository                         | `bare` — it has no working tree, so it is **not** a valid audit target; nothing crashes |
| Directory _inside_ another repository   | `none` — discovery is capped at the project directory (`GIT_CEILING_DIRECTORIES`)       |
| Git missing / timed out / unsafe config | `unavailable` with a reason — never guessed, and **never equivalent to `none`**         |

Submodules are **not** initialized, updated or recursed into
(`--ignore-submodules=all`); Git LFS is never invoked, so LaraDogs audits the
**visible filesystem state** only (LFS pointer files are just small text files).

### Dirty semantics

`dirty = true` when `git status` reports **any** tracked change (staged or
unstaged) **or** any untracked, non-ignored file. Ignored files (`.gitignore`)
and submodule content do not count. Only the boolean is stored — never the
file list.

A dirty scan is **not reproducible from its SHA alone**, and LaraDogs never
claims otherwise (`GitSnapshot::isReproducible()` is `true` only for a clean
repository with at least one commit). No recursive content hashing is done.

## Security model

The target repository is **untrusted**. Real-binary tests
(`tests/Unit/Audit/Source/GitInspectorSecurityTest.php`) use temporary
repositories with controlled marker files; each hostile fixture is first
shown to be genuinely dangerous with a plain `git status` (the control),
then inspected — and nothing runs.

Verified against a real Git binary: a repository's own `.git/config` can make
a plain `git status` **execute arbitrary commands** — `core.fsmonitor` and
`filter.<name>.clean|smudge|process` (a `.gitattributes` `filter=` plus a
config entry). `alias.*` is _not_ a vector (Git never lets an alias shadow a
built-in), but it is tested anyway. Therefore:

- **argv only, never a shell.** Commands go through the existing
  `ProcessRunner` (`SymfonyProcessRunner`, no `sh -c`, no `shell_exec`, no
  backticks); the Git layer is statically checked for shell calls.
- **Only four read-only, local subcommands**: `rev-parse
--is-bare-repository`, `config --list -z`, `status --porcelain=v2 …`,
  `cat-file commit <sha>`. Never `fetch`, `pull`, `push`, `ls-remote`,
  `clone`, `checkout`, `submodule`, `lfs`. Reading the configured origin URL
  is allowed; contacting it never happens.
- **The repository never controls the executable, the flags or the
  environment.** Only the validated project path and a hex SHA (checked
  against `^[0-9a-f]{40|64}$`) are ever placed in argv.
- **Explicit minimal environment.** The runner suppresses every variable not
  listed. Git runs with `PATH`, a LaraDogs-controlled `HOME` (never created,
  never read — no `~/.gitconfig`, no `.git-credentials`), `LC_ALL=C`,
  `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`,
  `GIT_TERMINAL_PROMPT=0`, `GIT_OPTIONAL_LOCKS=0`, `GIT_NO_REPLACE_OBJECTS=1`,
  `GIT_PAGER`/`PAGER=cat`, and `GIT_CEILING_DIRECTORIES`. `GIT_ASKPASS`,
  `SSH_ASKPASS`, `GIT_EXTERNAL_DIFF`, `GIT_DIR`, `GIT_SSH_COMMAND`,
  credentials, and every other inherited variable never reach Git.
- **Config neutralization and the include boundary (Phase 9.1).** Verified
  against a real Git binary: a plain `git config --list` **follows**
  `include.path` and `includeIf.*.path` (every `includeIf` form — `gitdir`,
  `onbranch`, `hasconfig` — and absolute, `../`, `~/` and symlinked paths),
  reading files **outside the repository**, and included config can execute
  commands (`core.fsmonitor`, `filter.*`, a credential helper). LaraDogs
  therefore reads the config with `git config --no-includes --list -z` (which
  executes nothing and reads no external file, yet still lists every include
  _declaration_ and the linked-worktree `config.worktree`) and **refuses**
  the repository as `unavailable: unsafe_config` if it declares **any**
  `include.*` / `includeIf.*` key or redirects the work tree
  (`core.worktree`). There is deliberately **no** "safe subset": no
  containment/symlink logic to get wrong — repository-controlled includes are
  simply unsupported. `core.fsmonitor` is forced `false` and `core.hooksPath`
  to `/dev/null` on every command, and every `filter.*.clean|smudge|process|
required` the config defines (including in `config.worktree`) is overridden
  on the command line. `alias.*`, `credential.helper`, `core.sshCommand`,
  `core.askPass`, `diff.external`/`textconv`, `core.pager` and `core.editor`
  are not reachable from the four commands used (tested to stay inert).
  Residual: a repository that rewrites its own config _between_ the config read
  and the following command is outside this model (the always-forced keys and
  the read-only mount limit the blast radius; the target is expected to be
  mounted `:ro`).
- **No hooks.** None of the four commands runs a hook; `core.hooksPath` and
  `core.fsmonitor` (the hook-based fsmonitor) are neutralized regardless.
- **No pager, no external diff, no credential helper, no askpass, no
  network.** None is reachable; tests prove none is invoked.
- **`safe.directory` is command-scoped.** Docker bind mounts commonly trigger
  Git's dubious-ownership protection. Each command passes
  `-c safe.directory=<the one validated real path>` — never `*`, never
  written to any config file, never a global/system config change. The
  path was registered by an operator, so trusting it for reading is
  intentional — which is exactly why the config neutralization above exists.
- **Read-only friendly.** `--no-optional-locks` /`GIT_OPTIONAL_LOCKS=0`: an
  inspection never writes the index or creates `index.lock`, so it works on
  the `/projects:ro` mount and never mutates the target (tested).
- **Bounded.** Output is capped per stream (default 64 KiB, its own runner —
  not the analyzers' 5 MB), and the whole inspection has one **10-second
  wall-clock budget** (`LARADOGS_GIT_BUDGET_SECONDS`): these are O(index)
  metadata reads, never history walks, so a healthy repository answers in
  milliseconds while an unresponsive one (slow bind mount, huge tree) must not
  stall a page or an audit. On expiry the state is `unavailable: timeout` —
  never a guess. This is far below Semgrep's 1800 s on purpose.

Failure to inspect is a **state**, not an exception: `unavailable` with one of
`git_unavailable`, `timeout`, `unsafe_config`, `unsafe_ownership`,
`output_too_large`, `path_unavailable`, `git_error`.

### Remote sanitization

`remote.origin.url` may embed credentials. `RemoteUrlSanitizer` persists and
renders only a sanitized form:

| Raw                                             | Stored / shown                            |
| ----------------------------------------------- | ----------------------------------------- |
| `https://user:token@example.com/org/repo.git`   | `https://example.com/org/repo.git`        |
| `https://example.com/org/repo.git?token=x#f`    | `https://example.com/org/repo.git`        |
| `git@host:org/repo.git` (SCP-style)             | `host:org/repo.git`                       |
| `ssh://git@host:2222/org/repo.git`              | `ssh://host:2222/org/repo.git`            |
| `/home/me/repos/x.git`, `../x`, `~/x`, `C:\x`   | `Local remote`                            |
| `file:///home/me/x.git`                         | `Local remote`                            |
| anything malformed / unknown scheme / ambiguous | `Unrecognized remote` (never echoed back) |

Everything before the **last** `@` of the authority is dropped (even a password
containing `@`); query and fragment are dropped; an `@` left in the path makes
the URL ambiguous and therefore unrecognized. Generic Git only — no host is
assumed or special-cased. A local-path remote never leaks a host path.

## Audit snapshot and source consistency

`ScanRunner` captures the snapshot **before any analyzer runs** and persists
it with the scan (`ScanRecorder::beginRunning`). A historical scan never
derives its revision from the current filesystem: it keeps its own snapshot
forever, and pre-Phase-9 scans keep `source_type = null` ("not captured") —
nothing is fabricated.

It captures Git again **after** the analyzers and derives a **source-integrity
verdict** (`scans.source_consistent`, plus `scans.source_integrity_reason`):

| `source_consistent` | Meaning                                                                                              |
| ------------------- | ---------------------------------------------------------------------------------------------------- |
| `true`              | **Verified**: a **clean** Git repository **with a commit**, identical before and after the analyzers |
| `false`             | **Integrity not established** — `source_integrity_reason` says why (below). Fail closed              |
| `null`              | A **genuine non-Git** target (no integrity claim exists), a legacy scan that was never captured      |

Source-consistency guarantees are **Git-derived**: LaraDogs does not pretend a
non-Git target is reproducible.

### Source-integrity truth table

| Situation (before → after)                                    | `source_consistent` | Reason                 | Finding auto-resolution | Absence-based gate rules       |
| ------------------------------------------------------------- | ------------------- | ---------------------- | ----------------------- | ------------------------------ |
| Non-Git → non-Git (stable)                                    | `null`              | —                      | normal                  | normal (may Pass)              |
| Clean Git with a commit, identical                            | `true`              | —                      | normal                  | normal (may Pass)              |
| Clean → dirty during the audit                                | `false`             | `changed_during_audit` | **none**                | **Indeterminate** (never Pass) |
| Commit changes                                                | `false`             | `changed_during_audit` | **none**                | **Indeterminate**              |
| Branch / detached identity changes                            | `false`             | `changed_during_audit` | **none**                | **Indeterminate**              |
| Kind of source changes (e.g. non-Git → Git)                   | `false`             | `changed_during_audit` | **none**                | **Indeterminate**              |
| **Dirty at start** (even if identical afterwards)             | `false`             | `dirty_at_start`       | **none**                | **Indeterminate**              |
| Git repository **without commits** (unborn)                   | `false`             | `no_commits`           | **none**                | **Indeterminate**              |
| **Bare** repository                                           | `false`             | `bare_repository`      | **none**                | **Indeterminate**              |
| Git **unavailable** (no binary, timeout, Git error), any side | `false`             | `unavailable`          | **none**                | **Indeterminate**              |
| Repository config **refused** (`unsafe_config`), any side     | `false`             | `unsafe_config`        | **none**                | **Indeterminate**              |

In every `false` row **positive findings are still ingested** and a **proven**
policy violation is still **Failed** (proven violation > missing integrity
evidence). `analyzer-status` / `analyzer-coverage` rules judge the execution
itself and are unaffected. Phase 8's exit codes (`0/1/2/3/4`) are unchanged.

Notes:

- **Dirty at start.** `dirty = true` staying `true` proves nothing — a file may
  change again inside an already-dirty tree, and no worktree hashing is done.
  Rather than try to detect that, such an audit is simply **never trusted for
  absence**. The exact mutation may still go undetected; its absence-based
  conclusions are no longer relied on, which makes the old limitation
  ("edits inside an already-dirty tree are not detected") harmless for
  findings and gates. A dirty audit is also never called "reproducible".
- **`none` ≠ `unavailable`.** `none` means the target is genuinely not a Git
  repository. `unavailable` means Git provenance could not be read/trusted; it
  is fail-closed and must not behave "as though Git awareness did not exist".
  (If the Git binary is missing, every audit is therefore unverified — the
  Docker image ships `git`.)
- **Wording.** The dashboard/CLI say the source **"changed"** only for
  `changed_during_audit`; otherwise they say integrity **could not be proven /
  verified** (e.g. _"Source integrity could not be proven because the working
  tree was dirty when the audit began."_, _"Git source integrity could not be
  verified."_).

Nothing reruns automatically.

### Fail-closed consequences of an unverified scan

A `source_consistent = false` scan cannot support **absence**: it may mix
evidence from several source states, or its source could not be proven stable.

- **Findings lifecycle.** `FindingReconciler` never auto-resolves anything from
  such a scan — _absence_ of a finding proves nothing (same philosophy as
  `AnalyzerCoverage`). **Positive** observations are still recorded normally
  (new findings, re-detection, reopening). The Engine/Findings dependency
  direction is untouched: the guard lives in the reconciler.
  See [`../auditing/findings-lifecycle.md`](../auditing/findings-lifecycle.md).
- **Quality Gates.** The gate evaluator stays pure; it receives the
  consistency flag as evidence. Absence-based rules (`max-open-findings`
  within its limit, `no-new-severity`) cannot be **Passed** — they become
  **Indeterminate**; a **proven** violation still **Failed**. Rules about an
  analyzer's own execution/coverage are unaffected. Phase 8's exit codes
  (`0/1/2/3/4`) are unchanged. See
  [`../quality-gates/README.md`](../quality-gates/README.md).
- **UI/CLI.** The scan is clearly marked; `laradogs:project:audit` prints an
  explicit warning.

## Where you see it

- **`laradogs:inspect <path>`** — `Git: detected`, `Branch`, `Revision`,
  `Working tree`, sanitized `Origin`; `--json` adds a bounded `git` object.
  No network access.
- **`laradogs:project:audit`** — `Source: abc1234 · main · clean`, an explicit
  warning when the source changed during the audit, and a bounded
  `scan.source` JSON object: `{"type","commit","branch","detached","dirty",
"consistent"}` (`{"type":"none"}` for a non-repository; `null` for a legacy
  scan). No absolute path, credentials or Owner identity.
- **`laradogs:project:gate`** — the text output shows the audited revision; the
  JSON envelope (`project`, `scan`, `gate`, `exit_code`, `error`) and exit codes
  are **unchanged** (source metadata is available through
  `laradogs:project:audit --json`).
- **Project Detail** — a _Source_ card contrasting **Current source state**
  (request-time, read-only inspection) with **Last audited source state** (the
  immutable snapshot of the last completed scan), and _Source changed since last
  audit_ when they differ. The current state is a lazy prop: it runs on a full
  page load (and once when an active scan finishes), never on the 4-second
  active-scan poll.
- **Scan History** — a compact _Revision_ column (short SHA, branch, Dirty /
  Changed-during-audit). **Scan Detail** — the full immutable snapshot (full
  SHA, branch/detached, dirty, source consistent, commit time/subject, sanitized
  origin).
- **Projects list** — uses persisted data only; **no** Git subprocess per row.

## Docker

The runtime image now installs `git` (Debian bookworm). `app` (Project Detail,
`laradogs:inspect`) and `worker` (persisted audits) invoke it; `scheduler` uses
the same image but only enqueues audits. Nothing new is published on a host
port and the projects mount stays `/projects:ro` — inspection needs no write
access and no host Git configuration or credentials. Overrides:
`LARADOGS_GIT_BINARY`, `LARADOGS_GIT_BUDGET_SECONDS`,
`LARADOGS_GIT_MAX_OUTPUT_BYTES`, `LARADOGS_GIT_HOME`.

## Limitations

- Local, observed metadata only — no hosted-repository awareness.
- Edits inside an already-dirty tree are not _detected_ — but such a scan is
  unverified (`dirty_at_start`), so nothing is auto-resolved from it and gates
  cannot Pass on absence.
- Dirty scans and repositories with no commits are not reproducible from the
  SHA; LaraDogs does not hash the working tree.
- Repository-controlled `include`/`includeIf` are unsupported (refused).
- A project directory **inside** a larger repository (e.g. a monorepo
  subdirectory) is reported as not-a-repository.
- Submodule content and Git LFS objects are not examined.
- If Git cannot be read the scan is unverified (`unavailable` / `unsafe_config`), never treated like a non-Git target.

## Out of scope and future work

Deliberately not built: `git fetch`/`pull`/`push`, clone or checkout
management, GitHub/GitLab/Bitbucket APIs, pull-request integration,
webhooks, auto-checkout, merge/rebase/reset, submodule update, Git LFS
fetch. **Phase 10** builds a CI command (`laradogs:ci:audit`) and GitHub
Check Run reporting directly on top of this local, read-only foundation —
see [`../ci/README.md`](../ci/README.md) and
[`../integrations/github.md`](../integrations/github.md) — without adding
any of the above (still no fetch/pull/push, no cloning, no webhooks). A
future phase may add the remaining hosted-repository scope (GitHub API
repository browsing, webhooks, _managed_/LaraDogs-owned clones, continuous
"watch and re-audit" monitoring).
