# Performance Rules

**Status: Implemented (Phase 6).** See [`../rules.md`](../rules.md) for
the catalog convention. This phase's own spec is explicit that
performance rules must be **extremely conservative** this phase — no
simplistic N+1 detection based on `foreach` + relation access without
real evidence. Exactly one rule shipped, matching the phase spec's own
worked example.

## `laradogs.performance.eloquent.unbounded-all`

- **Category:** Performance · **Severity:** Info (`INFO`) · **Confidence:**
  Low
- **What it detects:** a call to `Model::all()` (a static call on a
  capitalized/PascalCase-looking receiver — see the false-positive note
  below).
- **Why it matters:** `Model::all()` loads every row of the table into
  memory at once, with no pagination or limit. For a table that grows with
  user activity, this is a genuine, common performance hotspot.
- **Example (flagged):**
    ```php
    return User::all();
    ```
- **Example (not flagged — bounded alternatives):**
    ```php
    return User::paginate(15);
    return User::query()->limit(50)->get();
    return User::cursor();
    ```
- **Why Info severity and Low confidence — this is deliberately NOT framed
  as a bug:** this rule is a **review signal, not a confirmed problem**.
  `::all()` is entirely appropriate for a small, bounded table (a
  lookup/reference/settings table) and only a real problem for a table
  that grows with user activity — this rule has no way to distinguish the
  two. The finding's own message says exactly this ("may be entirely fine
  ... or a genuine problem"), never asserting a confirmed issue.
- **Limitations / false positives — the fix that made this rule usable at
  all:** an earlier, unrestricted version of this pattern (`$MODEL::all()`
  with no restriction on the receiver) was empirically confirmed to ALSO
  match `$request->all()` — Semgrep's PHP matcher treats `->` and `::` as
  interchangeable when the receiver is a metavariable (see
  [`security-rules.md`](security-rules.md)'s own cross-cutting note on
  this exact issue, discovered via this rule's interaction with the mass
  assignment rule during testing: `User::create($request->all())` was
  triggering BOTH rules at once). Fixed with a `metavariable-regex`
  requiring the receiver to start with an uppercase letter
  (`^[A-Z]`) — a `$`-prefixed variable never satisfies this, while a
  PascalCase class reference like `User` does. Remaining, accepted
  limitation: this still cannot distinguish a genuine Eloquent `Model`
  from any other PascalCase-named class exposing its own unrelated static
  `all()` method (e.g. a `Collection`-like value object, an enum-like
  class) — Semgrep's PHP matching has no type resolution to make that
  distinction. Given the Low confidence and Info severity already
  reflecting "hotspot, not confirmed bug," this residual imprecision is
  accepted rather than engineered away.
- **Remediation:** if this table can grow unbounded, replace `::all()`
  with `::paginate()`/`::cursor()`/a bounded `::limit()`. If the table
  size is and will remain small, this finding can be safely dismissed —
  it is intentionally advisory.
- **References:** https://laravel.com/docs/eloquent#retrieving-models,
  https://laravel.com/docs/pagination

## `laradogs.configuration.debug.app-debug-default-true`

Category is `Configuration`, not `Performance` — documented here anyway
since it is this phase's only non-Security/Quality rule besides the one
above, and a fourth single-rule file felt like unnecessary ceremony (see
[`../rules.md`](../rules.md)'s own "don't build a giant framework"
principle).

- **Category:** Configuration · **Severity:** Medium (`WARNING`) ·
  **Confidence:** High
- **What it detects:** `env('APP_DEBUG', true)` — an explicit `true`
  second argument — anywhere in first-party PHP (in practice, always a
  `config/*.php` file).
- **Why it matters:** this defaults debug mode **ON** whenever the
  `APP_DEBUG` environment variable is missing or unset. Laravel's own
  skeleton ships this defaulted to `false` (confirmed by reading this very
  repository's own `config/app.php`: `env('APP_DEBUG', false)`) — if the
  `true` default is ever reached in a deployed environment (e.g. a missing
  `.env` entry), detailed stack traces, file paths, and
  environment/config values may be exposed to visitors via Whoops.
- **Example (unsafe):** `'debug' => (bool) env('APP_DEBUG', true),`
- **Example (safe — Laravel's own real default):**
  `'debug' => (bool) env('APP_DEBUG', false),`
- **This rule never reads `.env`, `.env.example`, or any dotenv file at
  all** — it matches a literal PHP function-call pattern
  (`env('APP_DEBUG', true)`) in `.php` files only, which
  `SemgrepTargetCollector` already collects; no target-collector or
  applicability change was needed for it. A target project's real
  `APP_DEBUG` VALUE (in its actual `.env`, which LaraDogs never reads
  anyway per its own standing security model) is completely irrelevant to
  this rule — it only ever inspects the DEFAULT written into the PHP
  source.
- **Limitations / false positives:** matches the literal string
  `env('APP_DEBUG', true)` exactly — a semantically-equivalent but
  differently-spelled variant (e.g. `env('APP_DEBUG', TRUE)`,
  whitespace/quote-style variations Semgrep's parser doesn't already
  normalize) may not match; not tested exhaustively beyond the canonical
  form. `env()` calls for keys OTHER than `APP_DEBUG` defaulted to `true`
  are deliberately never flagged — this rule is scoped narrowly to the one
  key with a well-understood, severe consequence when defaulted wrong.
- **Remediation:** default `APP_DEBUG` to `false`
  (`env('APP_DEBUG', false)`) so a missing environment variable fails
  safe, and set the real value explicitly per environment (`true` only for
  local development).
- **CWE:** [CWE-489](https://cwe.mitre.org/data/definitions/489.html) —
  Active Debug Code.
- **References:** https://cwe.mitre.org/data/definitions/489.html,
  https://laravel.com/docs/configuration#debug-mode
