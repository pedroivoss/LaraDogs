<?php

namespace App\Audit\Analyzers\Semgrep;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\Confidence;

/**
 * One entry in {@see SemgrepRuleCatalog} — a LaraDogs-controlled rule id
 * paired with the LaraDogs-specific concepts Semgrep itself has no notion
 * of ({@see AnalyzerCategory}, {@see Confidence}). Deliberately does NOT
 * carry severity: severity comes from the rule's own YAML `severity:` key,
 * which Semgrep already reports verbatim per match (see
 * `SemgrepAnalyzer::mapSeverity()`), so duplicating it here would be a
 * second source of truth that could silently drift from the YAML file.
 */
final readonly class SemgrepRule
{
    public function __construct(
        public string $id,
        public AnalyzerCategory $category,
        public Confidence $confidence,
    ) {}
}
