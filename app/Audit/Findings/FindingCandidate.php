<?php

namespace App\Audit\Findings;

use App\Audit\Engine\Contracts\AnalyzerCategory;

/**
 * A scanner-agnostic "an analyzer observed this" DTO — what a real
 * analyzer (Phase 4+) will eventually produce from raw scanner output,
 * before fingerprinting or persistence ever happens. No real analyzer
 * produces this yet; synthetic candidates are used in tests to exercise
 * ingestion without a real scanner integration.
 *
 * Deliberately NOT a `Finding` (see ADR-0010): a Finding is LaraDogs'
 * persistent, cross-scan identity for an issue; a candidate is just one
 * scan's raw claim, which may or may not match an existing Finding once
 * fingerprinted.
 */
final readonly class FindingCandidate
{
    /**
     * @param  list<string>  $references
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $ruleId,
        public string $analyzerId,
        public AnalyzerCategory $category,
        public Severity $severity,
        public Confidence $confidence,
        public string $title,
        public ?string $description = null,
        public ?string $impact = null,
        public ?string $recommendation = null,
        public ?string $filePath = null,
        public ?int $lineStart = null,
        public ?int $lineEnd = null,
        public ?string $codeSnippet = null,
        public ?string $contextCode = null,
        public ?string $cwe = null,
        public ?string $cve = null,
        public array $references = [],
        public array $metadata = [],
        public ?string $ruleVersion = null,
        public ?string $analyzerVersion = null,
    ) {}
}
