<?php

namespace App\Audit\Analyzers\Semgrep;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\Confidence;

/**
 * The full, minimal manifest of LaraDogs' own bundled Semgrep ruleset —
 * what Phase 5's spec calls a "rule catalog": before ANY scan runs, this
 * class already knows every rule id that will execute, which LaraDogs
 * category and confidence policy each one carries, and where the actual
 * rule definitions (YAML, read directly by Semgrep — never parsed by PHP)
 * live on disk.
 *
 * Deliberately not a database table, a config-driven loader, or anything
 * that reads {@see rulesFilePath()} itself: the rule id list below and the
 * YAML file's own `id:` keys are two independent, manually-kept-in-sync
 * declarations of the same rules (verified by
 * `tests/Unit/Audit/Analyzers/Semgrep/SemgrepRuleCatalogTest.php`, which
 * asserts every id here appears verbatim in the real YAML file) — adding a
 * YAML-parsing dependency just to derive this list from the file itself
 * would be premature for a dozen rules (see docs/auditing/rules.md).
 *
 * Phase 5 shipped a small, proof-of-vertical ruleset (3 rules). Phase 6
 * added the first genuinely useful Laravel-aware rules on top of it —
 * still deliberately small (~8-15 rules total, rule quality over rule
 * count) and still not a comprehensive Laravel security scanner. See
 * docs/auditing/rules/security-rules.md,
 * docs/auditing/rules/quality-rules.md, and
 * docs/auditing/rules/performance-rules.md.
 */
final class SemgrepRuleCatalog
{
    /**
     * Independent from the LaraDogs application version and the installed
     * Semgrep binary version (see {@see AnalyzerCoverage::$rulesetVersion}
     * and ADR-0010's amendment) — bump this whenever the bundled YAML
     * file's rules meaningfully change (a rule added, removed, or its
     * matching behavior altered), never merely as a release marker. Never
     * consulted by {@see \App\Audit\Engine\Execution\AnalyzerCoverage::verifies()}
     * — provenance only.
     *
     * `2026.09.2` (Phase 6): added the first Laravel-aware ruleset (9 new
     * rules — SQL/raw-query, Blade/XSS, command execution, filesystem/path,
     * open redirect, mass assignment, a second debug helper, a debug-config
     * check, and one conservative performance hotspot) alongside the 3
     * Phase 5 proof-of-vertical rules. See
     * docs/auditing/rules/security-rules.md,
     * docs/auditing/rules/quality-rules.md, and
     * docs/auditing/rules/performance-rules.md for the full rationale.
     *
     * `2026.09.3` (Phase 6.1, real-world validation against allimaPanel):
     * no rule added, removed, or renamed — matcher-precision-only changes
     * to 3 existing rules' patterns (`laradogs.security.filesystem.
     * tainted-path`, `laradogs.security.sql.tainted-raw-query`,
     * `laradogs.performance.eloquent.unbounded-all`), fixing real false
     * positives found scanning a real, 908-file production Laravel app.
     * `severity`/`confidence`/`category`/rule ids unchanged. See
     * docs/auditing/rules/security-rules.md and
     * docs/auditing/rules/performance-rules.md for the full before/after
     * account.
     */
    public const string RULESET_VERSION = '2026.09.3';

    /**
     * @var list<array{id: string, category: AnalyzerCategory, confidence: Confidence}>
     */
    private const array RULES = [
        [
            'id' => 'laradogs.quality.debug.dd-call',
            'category' => AnalyzerCategory::Quality,
            'confidence' => Confidence::High,
        ],
        [
            'id' => 'laradogs.quality.debug.var-dump-call',
            'category' => AnalyzerCategory::Quality,
            'confidence' => Confidence::High,
        ],
        [
            'id' => 'laradogs.security.php.eval-usage',
            'category' => AnalyzerCategory::Security,
            'confidence' => Confidence::Medium,
        ],
        [
            'id' => 'laradogs.security.sql.tainted-raw-query',
            'category' => AnalyzerCategory::Security,
            'confidence' => Confidence::Medium,
        ],
        [
            'id' => 'laradogs.security.blade.raw-output-tainted',
            'category' => AnalyzerCategory::Security,
            'confidence' => Confidence::Medium,
        ],
        [
            'id' => 'laradogs.security.command.tainted-exec',
            'category' => AnalyzerCategory::Security,
            'confidence' => Confidence::Medium,
        ],
        [
            'id' => 'laradogs.security.filesystem.tainted-path',
            'category' => AnalyzerCategory::Security,
            'confidence' => Confidence::Medium,
        ],
        [
            'id' => 'laradogs.security.redirect.tainted-open-redirect',
            'category' => AnalyzerCategory::Security,
            'confidence' => Confidence::Medium,
        ],
        [
            'id' => 'laradogs.security.mass-assignment.request-all',
            'category' => AnalyzerCategory::Security,
            'confidence' => Confidence::Medium,
        ],
        [
            'id' => 'laradogs.quality.debug.ray-call',
            'category' => AnalyzerCategory::Quality,
            'confidence' => Confidence::High,
        ],
        [
            'id' => 'laradogs.configuration.debug.app-debug-default-true',
            'category' => AnalyzerCategory::Configuration,
            'confidence' => Confidence::High,
        ],
        [
            'id' => 'laradogs.performance.eloquent.unbounded-all',
            'category' => AnalyzerCategory::Performance,
            'confidence' => Confidence::Low,
        ],
    ];

    /**
     * Absolute path to the single YAML file passed to Semgrep's
     * `--config`. Deliberately a plain resource file, not
     * `config/laradogs.php` — a Semgrep ruleset is rule DATA Semgrep itself
     * parses, not PHP application configuration (see
     * docs/auditing/rules.md).
     */
    public static function rulesFilePath(): string
    {
        return resource_path('audit/semgrep/rules/laradogs-rules.yml');
    }

    /**
     * @return list<SemgrepRule>
     */
    public static function rules(): array
    {
        return array_map(
            static fn (array $rule): SemgrepRule => new SemgrepRule($rule['id'], $rule['category'], $rule['confidence']),
            self::RULES,
        );
    }

    /**
     * @return list<string>
     */
    public static function ruleIds(): array
    {
        return array_column(self::RULES, 'id');
    }

    public static function find(string $ruleId): ?SemgrepRule
    {
        foreach (self::RULES as $rule) {
            if ($rule['id'] === $ruleId) {
                return new SemgrepRule($rule['id'], $rule['category'], $rule['confidence']);
            }
        }

        return null;
    }
}
