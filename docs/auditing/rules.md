# Rule Catalog, Identity, and Versioning

**Status: Implemented (Phase 5 foundation; Phase 6 adds the first
Laravel-aware rules on top of it).** This document defines LaraDogs'
conventions for LaraDogs-controlled static analysis rules — currently only
Semgrep rules (see [`analyzers/semgrep.md`](analyzers/semgrep.md) and
[`static-analysis.md`](static-analysis.md)), but the convention is written
to extend to any future rule-based analyzer. For the actual rules and
their full rationale (what they detect, false-positive analysis,
remediation), see [`rules/security-rules.md`](rules/security-rules.md),
[`rules/quality-rules.md`](rules/quality-rules.md), and
[`rules/performance-rules.md`](rules/performance-rules.md) — this document
covers only the shared catalog/identity/versioning MECHANISM, not each
rule's own content.

## Rule catalog

A "rule catalog" is a small, code-level manifest that must answer, BEFORE
any scan executes: which rule ids will run, what ruleset version they
belong to, where the controlled rule definition file lives on disk, and
which LaraDogs-specific category/confidence policy each rule carries.

For Semgrep, this is `App\Audit\Analyzers\Semgrep\SemgrepRuleCatalog` — a
plain, static, code-level manifest (a `const array` plus a few static
methods), deliberately **not** a database table, a config-driven loader,
or anything that parses the bundled YAML file itself to derive its rule
id list. The catalog's rule id list and the YAML file's own `id:` keys are
two independently-maintained declarations of the same rules, kept in sync
by a drift test (`SemgrepRuleCatalogTest::it_never_drifts...`) that
asserts every catalog id appears verbatim in the real YAML file — a string
match, not a YAML parser. This was a deliberate choice: introducing a YAML
parsing dependency to derive 3 ids from a file is premature (see
[Dependencies](analyzers/semgrep.md#configuration) in the Semgrep analyzer
doc) — revisit only if/when the rule count grows enough that manual
sync becomes error-prone in practice.

**What lives in the catalog (PHP) vs. the rule file (YAML):**

| Concept                                    | Lives in                            | Why                                                                                                                                                                                                                                         |
| ------------------------------------------ | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Rule id, pattern, message, YAML `severity` | Rule file (YAML)                    | Semgrep-native concepts — the engine parses and matches these directly.                                                                                                                                                                     |
| `cwe`, `references`, `remediation`         | Rule file (YAML), under `metadata:` | Semgrep passes a rule's own `metadata:` block through verbatim in its JSON output — no PHP-side YAML parsing needed. `remediation` (Phase 6) is read into `FindingCandidate::$recommendation` the same way `cwe`/`references` already were. |
| `AnalyzerCategory`, `Confidence`           | Catalog (PHP)                       | LaraDogs-specific concepts Semgrep has no notion of — keeping them in exactly one place (PHP) avoids two sources of truth silently drifting apart.                                                                                          |
| Ruleset version                            | Catalog (PHP)                       | A LaraDogs-level provenance concept, not a Semgrep concept.                                                                                                                                                                                 |

## Rule id convention

`laradogs.<category>.<subject>.<check>` — stable, unique, versionable,
independent of the rule's human-facing `message`/title (which can be
edited freely without changing identity). All 12 rule ids in the current
bundled ruleset:

- `laradogs.quality.debug.dd-call` (Phase 5)
- `laradogs.quality.debug.var-dump-call` (Phase 5)
- `laradogs.quality.debug.ray-call` (Phase 6)
- `laradogs.security.php.eval-usage` (Phase 5)
- `laradogs.security.sql.tainted-raw-query` (Phase 6)
- `laradogs.security.blade.raw-output-tainted` (Phase 6)
- `laradogs.security.command.tainted-exec` (Phase 6)
- `laradogs.security.filesystem.tainted-path` (Phase 6)
- `laradogs.security.redirect.tainted-open-redirect` (Phase 6)
- `laradogs.security.mass-assignment.request-all` (Phase 6)
- `laradogs.configuration.debug.app-debug-default-true` (Phase 6)
- `laradogs.performance.eloquent.unbounded-all` (Phase 6)

See [`rules/security-rules.md`](rules/security-rules.md),
[`rules/quality-rules.md`](rules/quality-rules.md), and
[`rules/performance-rules.md`](rules/performance-rules.md) for what each
one actually detects, its severity/confidence rationale, and its known
limitations.

`<category>` is a short, coarse grouping word (`quality`, `security`, …)
— it is NOT the same as `AnalyzerCategory` (which lives in the catalog,
per above) and need not match it exactly, though it usually will read
naturally alongside it. `<subject>` names the broad area (`debug`, `php`,
`sql`, `blade`, `command`, `filesystem`, `redirect`, `mass-assignment`,
`eloquent`, …). `<check>` names the specific pattern. This is a naming
CONVENTION, not a machine-enforced grammar — there is no parser validating
the dotted shape; consistency is maintained by review and the drift test
described above.

**Note on earlier planning sketches:** Phase 5's version of this document
sketched two illustrative, NOT-yet-real rule ids as examples of the
convention: `laradogs.security.sql.raw-user-input` and
`laradogs.security.blade.unescaped-output`. Phase 6 implemented rules in
those same two areas, but under DIFFERENT, more precise final ids
(`laradogs.security.sql.tainted-raw-query` and
`laradogs.security.blade.raw-output-tainted`) once the actual detection
mechanism (taint mode for SQL; a generic-mode textual heuristic for Blade)
was empirically worked out — a reminder that a sketched id is never a
commitment, only a naming-convention illustration.

## Rule versioning — three independent concepts

Never conflate these:

1. **LaraDogs application version** (`config('laradogs.version')`) — the
   whole product's own version.
2. **Semgrep binary version** — `semgrep --version`, resolved and recorded
   per run (`SemgrepAnalyzer::$resolvedVersion`, persisted as a
   `FindingCandidate`'s `analyzerVersion`).
3. **Ruleset version** (`SemgrepRuleCatalog::RULESET_VERSION`, currently
   `'2026.09.3'` — bumped from Phase 5's `'2026.09.1'` when Phase 6 added
   9 new rules, then from `'2026.09.2'` when Phase 6.1 refined 3 existing
   rules' matcher precision with no rule added/removed/renamed) — bumped
   whenever the bundled rules meaningfully change (a rule added, removed,
   or its matching behavior altered), never merely as a release marker
   alongside (1). Persisted as a `FindingCandidate`'s `ruleVersion`, and
   optionally carried by `AnalyzerCoverage::$rulesetVersion` for
   traceability.

**Critical, standing rule (carried forward from Phase 3.1 — see
[ADR-0010's amendment](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md#amendment-phase-31-coverage-gated-auto-resolution)):**
`rulesetVersion` is **never** consulted by
`AnalyzerCoverage::verifies()` and can never, by itself, authorize or
block a Finding's auto-resolution. A ruleset version bump with the same
declared coverage rule-id-list changes nothing about resolution; coverage
changing (even under an unchanged version) changes everything. This is
enforced structurally: `AnalyzerCoverage::verifies()`'s implementation
simply never reads `$rulesetVersion` at all.

## Rule catalog storage

Bundled Semgrep rules live under `resources/audit/semgrep/rules/` — a
plain resource file Semgrep itself parses directly via `--config`, not
inside a giant PHP config array. `config/laradogs.php` only carries
operational settings (binary path, timeouts, byte limits, the settings
file path) — never rule content itself. See
[`analyzers/semgrep.md#configuration`](analyzers/semgrep.md#configuration).

## Rule safety — rules are input too

A rule file is data LaraDogs feeds into an external tool, same as any
other input crossing that boundary — it must be validated the same way.
An invalid rule YAML file must never produce a false-clean scan: verified
empirically that Semgrep itself refuses to run at all against an invalid
rule file (exit code `7`, a populated `errors` array, `results: []`) —
`SemgrepAnalyzer::run()` gates on `ProcessResult::successful()` before
ever trusting output, so this surfaces as `ExecutionStatus::Failed`, never
a clean pass. See
[`analyzers/semgrep.md`](analyzers/semgrep.md#json-schema-verified-against-the-real-cli-v11760)
for the full research.

## Trust boundary

See [ADR-0012](../architecture/decisions/ADR-0012-trusted-static-analysis-rules.md)
for the full architectural decision: rules executed by LaraDogs come only
from rules LaraDogs bundles, rules explicitly configured by the LaraDogs
operator, or (future) trusted rule packs — **never** from the audited
repository by default, and never from the remote Semgrep Registry during
a normal scan.
