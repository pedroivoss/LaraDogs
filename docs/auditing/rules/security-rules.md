# Security Rules

**Status: Implemented (Phase 5 adds `eval-usage`; Phase 6 adds the other
six).** See [`../rules.md`](../rules.md) for the catalog/identity/
versioning convention these rules follow, and
[`../analyzers/semgrep.md`](../analyzers/semgrep.md) for the analyzer that
runs them. All taint-mode rules below were verified against the real,
locally-installed `semgrep` CLI (1.176.0) with `--oss-only` — Semgrep's
intraprocedural taint analysis (source → sink propagation through
assignment, string concatenation, and string interpolation) is part of the
OSS engine, not a Pro-only feature. Every rule's positive, negative, and
safe fixtures are re-validated against the real binary on every run of
`tests/Feature/Audit/Analyzers/Semgrep/SemgrepLaravelRulesRealBinaryTest.php`
(opt-in, `LARADOGS_TEST_REAL_SEMGREP=1`).

**A note on message wording, throughout:** every rule below says
"possible"/"appears to" rather than asserting a confirmed vulnerability.
This is deliberate — see [`../rules.md`](../rules.md) and each rule's own
Confidence rationale: none of these are backed by full interprocedural
dataflow analysis, so overclaiming certainty would misrepresent what was
actually verified.

## `laradogs.security.php.eval-usage`

- **Category:** Security · **Severity:** High (`ERROR` in Semgrep's own
  vocabulary) · **Confidence:** Medium
- **What it detects:** any call to PHP's `eval()`.
- **Why it matters:** `eval()` executes a string as PHP code. If any part
  of that string can be influenced by user input, this is a direct remote
  code execution vector.
- **Example (unsafe):**
    ```php
    eval($request->input('expr'));
    ```
- **Example (safe alternative):**
    ```php
    match ($operation) {
        'add' => $a + $b,
        'sub' => $a - $b,
        default => throw new InvalidArgumentException(),
    };
    ```
- **Limitations / false positives:** flags every `eval()` call
  unconditionally — it does not check whether the argument is actually
  user-influenced (no taint mode; a plain pattern match). A constant,
  hardcoded `eval()` call (rare, but not impossible in some
  metaprogramming/build-tooling code) would still be flagged. Confidence is
  Medium, not High, for this reason.
- **Remediation:** replace with an explicit, restricted alternative — a
  `match`/`switch` over known cases, a whitelisted callable map, or a
  small, purpose-built parser. Never construct the evaluated string from
  user input, even partially.
- **CWE:** [CWE-95](https://cwe.mitre.org/data/definitions/95.html) —
  Improper Neutralization of Directives in Dynamically Evaluated Code
  ('Eval Injection').
- **References:** https://cwe.mitre.org/data/definitions/95.html

## `laradogs.security.sql.tainted-raw-query`

- **Category:** Security · **Severity:** High (`ERROR`) · **Confidence:**
  Medium
- **What it detects:** a value that appears to come directly from request
  input (`$request->input()`/`->get()`/`->query()`/`->post()`/`->all()`,
  `request()->input()`/`->get()`/`->query()`, or a superglobal —
  `$_GET`/`$_POST`/`$_REQUEST`) reaching `DB::raw()`, `whereRaw()`,
  `orderByRaw()`, `selectRaw()`, or `havingRaw()`, via **taint tracking**
  (direct pass-through, string concatenation, or string interpolation are
  all detected — not just a literal argument match).
- **Why it matters:** raw SQL fragments are not parameter-bound by
  Laravel's query builder. Unsanitized input reaching one can let an
  attacker alter the query structure itself (SQL injection).
- **Example (unsafe):**
    ```php
    $sort = $request->input('sort');
    $query->orderByRaw($sort);
    ```
- **Example (safe — parameter binding):**
    ```php
    $query->whereRaw('id = ?', [$id]);
    ```
- **Example (safe — this rule's own recognized sanitizers):**
    ```php
    $id = (int) $request->input('id');
    $query->whereRaw('id = '.$id); // cast neutralizes the taint
    ```
- **Limitations / false positives:**
    - Recognized sanitizers are narrow: `(int)`/`(float)` casts and
      `intval()`/`floatval()`. A value validated via an **allowlist ternary**
      (e.g. `$dir = $input === 'asc' ? 'asc' : 'desc';`) is not flagged
      either — but for a different reason: taint never reaches the sink at
      all in that shape, since only the literal branch values propagate, not
      the original tainted comparison operand (verified empirically). A more
      complex validation shape (a `match`/`switch` with a `default` that
      still forwards the original tainted value) is NOT recognized as safe
      and would still be flagged — a real, accepted limitation.
    - Taint is intraprocedural only: if the tainted value is passed into
      another function/method before reaching the sink, this rule cannot
      follow it across that call boundary.
    - `DB::raw(...)`/similar sinks written as a fully-qualified global
      reference (`\DB::raw(...)`, with a leading backslash) are not matched
      — verified empirically that Semgrep's PHP pattern matcher treats this
      as a different AST shape than the conventional `use
Illuminate\Support\Facades\DB;` + bare `DB::raw(...)` form nearly all
      real Laravel code uses.
    - **Phase 6.1 fix (real-world validation against allimaPanel,
      2026-09-08):** the sinks below (`whereRaw`/`orderByRaw`/`selectRaw`/
      `havingRaw`) previously ended in a trailing `...` to cover an
      optional bindings-array argument. Combined with Semgrep taint mode's
      default behavior — a sink match fires if taint reaches _anywhere_
      within the matched call, not just its named metavariable — this
      produced two confirmed real false-positive classes in a genuine 908-
      file production Laravel app, **on the exact safe pattern this rule's
      own remediation recommends**:
        1. `whereRaw('period = ?', [$tainted])` — a _bound_ parameter
           (Laravel's own documented-safe idiom) reaching only the
           bindings array, never the raw SQL string itself, was flagged.
        2. A "chain-cascade" duplicate: once one call in a fluent chain
           safely used tainted-but-bound data, every _later_ sink call
           chained off the same query-builder variable (a fully literal
           `selectRaw`/`orderByRaw`, no variable involved at all) was
           _also_ flagged, because the sink's own `$QB` receiver
           metavariable is otherwise unconstrained and can bind an
           arbitrarily long preceding chain.
           **Fix:** every sink is now written as fixed-arity alternatives (an
           explicit `$BINDINGS` metavariable instead of `...`) plus
           `focus-metavariable: $X`, restricting the taint check to the raw SQL
           string argument alone. Confirmed empirically this also already
           excludes a _third_ shape found in the same real app — a tainted
           value reaching only a ternary CONDITION _inline inside the sink's
           own raw-string argument_ (e.g. `selectRaw('...'.($status ===
'completed' ? 'a' : 'b'))`) — no further change was needed for that
           case once the fix above was in place. See
           `tests/Fixtures/semgrep/rules/sql-raw-query.php` for the exact
           regression fixtures (`safeBoundWhereRaw`, `safeChainThenSelectRaw`,
           `safeChainThenOrderByRaw`, `safeInlineTernaryInRawString`) and
           `RULESET_VERSION` `2026.09.3`.
- **Remediation:** prefer parameter bindings over raw SQL. When a raw
  fragment must include a value that cannot be bound (e.g. a column or
  sort-direction name), validate it against an explicit allowlist
  (`in_array($value, ['asc', 'desc'], true)`) or cast it to the expected
  scalar type.
- **CWE:** [CWE-89](https://cwe.mitre.org/data/definitions/89.html) — SQL
  Injection.
- **References:** https://cwe.mitre.org/data/definitions/89.html,
  https://laravel.com/docs/queries#raw-expressions

## `laradogs.security.blade.raw-output-tainted`

- **Category:** Security · **Severity:** High (`ERROR`) · **Confidence:**
  Medium
- **What it detects:** a Blade raw-output block (`{!! ... !!}`, which does
  **not** HTML-escape its content) whose expression textually contains a
  recognized request-input accessor (`->input(`, `->get(`, `->query(`,
  `->post(`, `->all()`, `request()->`, or a superglobal).
- **Why it matters:** Blade's `{{ }}` syntax escapes output automatically;
  `{!! !!}` deliberately does not. If the echoed value can contain markup
  and originates from user input, this is a direct stored/reflected XSS
  vector.
- **Example (unsafe):**
    ```blade
    <div>{!! $request->input('bio') !!}</div>
    ```
- **Example (safe):**
    ```blade
    <p>{{ $bio }}</p>
    ```
- **Limitations / false positives — the most important one in this
  ruleset:** this rule runs in Semgrep's `generic` language mode (Blade's
  `.blade.php` files mix HTML and Blade directives, which are not valid
  PHP syntax a `languages: [php]` rule could parse at all — verified
  empirically: a `{!! $X !!}` pattern in `languages: [php]` fails with a
  rule parse error). `generic` mode has **no real dataflow/taint
  analysis** — this is a **textual heuristic only**, matching whether the
  raw-output expression's own text contains one of a fixed list of
  input-accessor substrings. Consequences, both directions:
    - **False negative (the significant one):** `$bio = $request->input('bio'); {!! $bio !!}` is **NOT** flagged — the variable name `$bio` alone gives no textual signal, even though the value genuinely is tainted. This rule only catches the DIRECT-call-inside-the-raw-block shape.
    - **False positive (narrow):** a variable that happens to be named/derived so its text contains one of the recognized substrings, but is not actually the raw request value (unlikely in practice given the substrings chosen — `->input(`, `request()->`, etc. — are fairly specific to real Request accessors).
- **Remediation:** use escaped output (`{{ $value }}`) unless the value is
  genuinely trusted HTML (e.g. rendered from a trusted Markdown/HTML
  sanitizer you control). If raw output is required, sanitize the value
  with an HTML purifier before echoing it, never the user-supplied value
  directly.
- **CWE:** [CWE-79](https://cwe.mitre.org/data/definitions/79.html) —
  Cross-site Scripting.
- **References:** https://cwe.mitre.org/data/definitions/79.html,
  https://laravel.com/docs/blade#displaying-data

## `laradogs.security.command.tainted-exec`

- **Category:** Security · **Severity:** High (`ERROR`) · **Confidence:**
  Medium
- **What it detects:** a value that appears to come directly from request
  input reaching `exec()`, `system()`, `shell_exec()`, `passthru()`, or
  `proc_open()`, via taint tracking (same propagation coverage as the SQL
  rule above).
- **Why it matters:** these functions execute a string as a shell command.
  Unsanitized input reaching one is a direct OS command injection vector.
- **Example (unsafe):**
    ```php
    $cmd = $request->input('cmd');
    exec($cmd);
    ```
- **Example (safe — escaped):**
    ```php
    $file = escapeshellarg($request->get('file'));
    shell_exec('cat '.$file);
    ```
- **Example (safe — LaraDogs' own preferred pattern, see ADR-0011):** an
  argv array through Symfony Process, never a shell string at all — this
  is exactly how `SymfonyProcessRunner` itself is built.
- **Limitations / false positives:** recognized sanitizers are
  `(int)`/`(float)` casts, `intval()`/`floatval()`, and
  `escapeshellarg()`/`escapeshellcmd()`. A value passed through a
  different, equally-valid escaping mechanism this rule doesn't recognize
  would still be flagged. Taint is intraprocedural only (see the SQL
  rule's limitations).
- **Remediation:** avoid building shell commands from user input entirely.
  If unavoidable, escape every user-controlled argument with
  `escapeshellarg()`/`escapeshellcmd()` — or, far preferably, use Symfony
  Process with an argv array so there is no shell to inject into at all.
- **CWE:** [CWE-78](https://cwe.mitre.org/data/definitions/78.html) — OS
  Command Injection.
- **References:** https://cwe.mitre.org/data/definitions/78.html,
  https://www.php.net/manual/en/function.escapeshellarg.php

## `laradogs.security.filesystem.tainted-path`

- **Category:** Security · **Severity:** High (`ERROR`) · **Confidence:**
  Medium
- **What it detects:** a value that appears to come directly from request
  input reaching `file_get_contents()`, `file_put_contents()`, `unlink()`,
  `fopen()`, `Storage::get()`, `Storage::disk(...)->get()`,
  `Storage::put()`, or `Storage::delete()`, via taint tracking.
- **Why it matters:** if the value can contain `../` segments or an
  absolute path, this may allow reading, writing, or deleting a file
  outside the intended directory (path traversal / arbitrary file access).
- **Example (unsafe):**
    ```php
    $path = $request->input('path');
    file_get_contents($path);
    ```
- **Example (safe):**
    ```php
    $name = basename($request->input('file'));
    Storage::get('uploads/'.$name);
    ```
- **Limitations / false positives:** recognized sanitizers are
  `basename()` (strips directory components — a real, meaningful
  mitigation, though not a complete one on its own: it does not validate
  the resulting filename against an allowlist) and numeric casts. Taint is
  intraprocedural only.
    - **Phase 6.1 fix #1 (real-world validation against allimaPanel,
      2026-09-08) — content vs. path confusion:** every sink here
      previously ended in a trailing `...` to cover optional trailing
      arguments (e.g. `file_put_contents`'s flags/context, `Storage::put`'s
      options array). Combined with Semgrep taint mode's default
      "taint anywhere in the match" behavior, this produced a confirmed
      real false positive: `file_put_contents($safeUuidPath,
$taintedImageContent)` — a fully server-generated, `Str::uuid()`-
      based PATH with tainted CONTENT — was flagged as path traversal,
      even though the path argument itself was never influenced by user
      input at all. **Fix:** every sink is now written as fixed-arity
      alternatives (explicit metavariables for every argument, never
      `...`) plus `focus-metavariable: $X` restricting the check to the
      path argument alone.
    - **Phase 6.1 fix #2 — cross-call return-value over-tainting:** a
      second, distinct real false-positive class: a tainted value passed
      as an argument to a helper/service method (e.g. an image-crop
      service) or used in an Eloquent `->where($tainted)->get()` call,
      whose RETURN VALUE was then conservatively treated as tainted by
      Semgrep's OSS engine — even though the callee actually returns a
      fresh, server-generated value (a new `Str::uuid()`-based path; a
      previously-stored, non-attacker-controlled DB column). This rule now
      sets `options: { taint_assume_safe_functions: true }`, confirmed
      empirically to eliminate both real shapes above without weakening
      any of this rule's own already-tested guarantees (direct
      passthrough, string concatenation, string interpolation — none of
      which cross a function-call boundary — all still flagged
      correctly). **Accepted trade-off:** a helper that is a genuine
      TRANSPARENT wrapper (returns its tainted argument completely
      unchanged, with no sanitization or regeneration at all) is no longer
      flagged either — a real, accepted limitation, not fixed further this
      phase (no interprocedural taint analysis is being built). See
      `tests/Fixtures/semgrep/rules/filesystem-path.php`
      (`safeUuidPathTaintedContent`, `safeServiceRegeneratesPath`,
      `knownLimitationTransparentWrapper`) and `RULESET_VERSION`
      `2026.09.3`.
- **Remediation:** never build a filesystem path directly from user input.
  Use `basename()` to strip directory components, validate the result
  against an allowlist of permitted files/extensions, and prefer Laravel's
  `Storage` facade with a fixed disk root over raw filesystem functions.
- **CWE:** [CWE-22](https://cwe.mitre.org/data/definitions/22.html) — Path
  Traversal.
- **References:** https://cwe.mitre.org/data/definitions/22.html,
  https://laravel.com/docs/filesystem

## `laradogs.security.redirect.tainted-open-redirect`

- **Category:** Security · **Severity:** Medium (`WARNING`) ·
  **Confidence:** Medium
- **What it detects:** a value that appears to come directly from request
  input reaching `redirect()`, `redirect()->to()`, or `$redirect->to()`
  (a variable literally named `$redirect`/`$redirector`), via taint
  tracking.
- **Why it matters:** if the value is a full URL, an attacker can craft a
  link to your own trusted domain that silently redirects victims to an
  external, attacker-controlled site (open redirect) — commonly used in
  phishing.
- **Example (unsafe):**
    ```php
    $next = $request->input('next');

    return redirect($next);
    ```
- **Example (safe):**
    ```php
    return redirect()->route('home');
    ```
- **Why Medium, not High, severity:** an open redirect's real-world impact
  is generally lower than SQL injection/command execution/arbitrary file
  access above (it enables phishing/trust abuse, not direct data
  compromise or code execution) — reflected in severity, independent of
  this rule's own detection confidence.
- **Limitations / false positives:** a value validated as a relative,
  same-site path (e.g. rejecting anything containing `://` or starting
  with `//`) is control-flow-based, not a value-transform sanitizer
  pattern — this rule cannot recognize that mitigation and would still
  flag it. Only numeric casts are recognized sanitizers here. Taint is
  intraprocedural only.
- **Remediation:** prefer `redirect()->route('name')` or
  `redirect()->action(...)` with a named destination over a user-supplied
  URL. If a user-supplied "return to" URL is genuinely required, validate
  it is a relative, same-site path before redirecting.
- **CWE:** [CWE-601](https://cwe.mitre.org/data/definitions/601.html) —
  Open Redirect.
- **References:** https://cwe.mitre.org/data/definitions/601.html,
  https://laravel.com/docs/responses#redirects

## `laradogs.security.mass-assignment.request-all`

- **Category:** Security · **Severity:** Medium (`WARNING`) ·
  **Confidence:** Medium
- **What it detects:** the entire request payload (`$request->all()` or
  `request()->all()`) passed directly to `Model::create()` or
  `$model->update()`, via a plain structural pattern (not taint mode — the
  shape itself is the signal, no dataflow needed).
- **Why it matters:** if the model's `$fillable`/`$guarded` configuration
  is missing or too permissive, an attacker could set a field never
  intended to be user-controlled (e.g. a role, permission, or owner
  column) — classic mass assignment.
- **Example (unsafe):**
    ```php
    User::create($request->all());
    ```
- **Example (safe):**
    ```php
    $data = $request->validate(['name' => 'required', 'email' => 'required|email']);
    User::create($data);
    ```
- **Why Medium confidence, not High:** a well-configured `$fillable` list
  genuinely mitigates this (Eloquent only assigns fillable attributes),
  but this rule cannot see the model's own `$fillable`/`$guarded`
  definition — it can only see that the ENTIRE, unfiltered payload was
  passed with no explicit field selection at the call site. The finding's
  own message and remediation both say "review the model's `$fillable`
  list" rather than asserting exploitability.
- **Limitations / false positives:** the receiver metavariables (`$MODEL`
  for `::create()`, `$VAR` for `->update()`) use Semgrep's generic call
  matching, which does not distinguish an Eloquent model from any other
  class exposing a same-shaped `create()`/`update()` static/instance
  method — a non-Eloquent class with this exact call shape (rare) would
  also be flagged. `$request->all()`'s own receiver is deliberately left
  unrestricted (see the YAML file's own comment on this rule) — a narrow,
  accepted risk given how nested/specific the surrounding two-level
  pattern already is.
- **Remediation:** pass only validated, explicitly-selected fields —
  `$request->validate([...])` or `$request->only([...])` — instead of
  `->all()`. Review the model's `$fillable` list to confirm it does not
  include any field that should never be user-settable.
- **CWE:**
  [CWE-915](https://cwe.mitre.org/data/definitions/915.html) — Improperly
  Controlled Modification of Dynamically-Determined Object Attributes.
- **References:** https://cwe.mitre.org/data/definitions/915.html,
  https://laravel.com/docs/eloquent#mass-assignment

## A cross-cutting false-positive risk this ruleset had to fix: `->`/`::` receiver ambiguity

Empirically discovered while validating the rules above (not documentation
of a hypothetical risk — a real bug caught before shipping): **Semgrep's
PHP matcher treats `->` (method call) and `::` (static call) as
interchangeable when the receiver is a metavariable.** A source pattern
written as `$REQ->get(...)` was confirmed to ALSO match completely
unrelated static calls sharing the same method name — e.g. `Storage::get(...)`,
`Cache::get(...)`, `Model::query()` — turning values that never came from
user input at all into false "tainted" sources, and in one case producing
a false-positive Finding on a constant, hardcoded call with no request
input anywhere in the function.

**Fix:** every `$REQ->method(...)` source pattern across all four taint
rules above is restricted with a `metavariable-regex` requiring the
receiver to be spelled like a request variable
(`(?i)^\$?request$`) — verified to eliminate the false positive while
preserving every genuine positive case. The performance rule
(`laradogs.performance.eloquent.unbounded-all`, see
[`performance-rules.md`](performance-rules.md)) needed the same class of
fix for the identical reason (`$MODEL::all()` was also matching
`$request->all()`). See each rule's own YAML comment in
`resources/audit/semgrep/rules/laradogs-rules.yml` for the exact
restriction. This is recorded here, prominently, because it is exactly
the kind of subtle, tool-specific correctness issue this phase's own
"false positive testing is the top priority" instruction exists to catch
— and it would not have been caught without testing every rule together,
not just each rule in isolation.
