# Quality Rules

**Status: Implemented (Phase 5 adds `dd-call`/`var-dump-call`; Phase 6
adds `ray-call`).** See [`../rules.md`](../rules.md) for the catalog
convention and [`../analyzers/semgrep.md`](../analyzers/semgrep.md) for
the analyzer that runs these. All three are plain structural patterns —
no taint mode, no dataflow — since the pattern itself (the call exists at
all in first-party code) is the entire signal.

## `laradogs.quality.debug.dd-call`

- **Category:** Quality · **Severity:** Medium (`WARNING`) ·
  **Confidence:** High
- **What it detects:** any call to `dd(...)`.
- **Why it matters:** `dd()` halts execution and dumps variable state —
  left in code, it will break the request that reaches it in production.
- **Example (unsafe):** `dd($user);`
- **Example (safe):** removed entirely, or replaced with `Log::debug()`
  for anything that needs to persist.
- **Limitations / false positives:** none of note — `dd()` has no
  legitimate reason to exist in shipped, first-party production code.
  `SemgrepTargetCollector` already excludes `vendor/`/`node_modules/`, so
  a `dd()` call inside a dependency is never scanned.
- **Remediation:** remove the call. Use `dump()` locally (never committed)
  or structured logging (`Log::debug()`) for anything meant to persist.

## `laradogs.quality.debug.var-dump-call`

- **Category:** Quality · **Severity:** Medium (`WARNING`) ·
  **Confidence:** High
- **What it detects:** any call to `var_dump(...)`.
- **Why it matters:** prints raw variable state directly into the response
  body — should never ship to production.
- **Example (unsafe):** `var_dump($request->all());`
- **Limitations / false positives:** same as `dd-call` above.
- **Remediation:** remove the call. Use `Log::debug()`/`Log::info()` for
  anything that needs to persist.

## `laradogs.quality.debug.ray-call`

- **Category:** Quality · **Severity:** Medium (`WARNING`) ·
  **Confidence:** High
- **What it detects:** any call to the global `ray(...)` function (the
  [Spatie Ray](https://spatie.be/products/ray) debugging tool, popular in
  the Laravel ecosystem).
- **Why it matters:** `ray()` attempts to send data to the Ray desktop app
  over the network on every request it's called from — left in code, this
  both leaks debug data and adds unnecessary overhead/failed-connection
  noise in production.
- **Example (unsafe):** `ray($user)->red();`
- **Limitations / false positives:** this rule matches only the bare
  global function call (`ray(...)`) — verified empirically that a
  same-named METHOD call on an object (`$sunTracker->ray()`) is a
  different AST shape and is correctly not matched. If a project defines
  its own unrelated global `ray()` function (extremely unlikely — it would
  collide with the real package's own function, which most projects using
  the name at all have installed), this rule cannot distinguish that case.
- **Remediation:** remove the call before committing. Use `Log::debug()`
  for anything that needs to persist beyond local debugging.
