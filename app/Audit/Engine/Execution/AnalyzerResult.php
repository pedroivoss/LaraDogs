<?php

namespace App\Audit\Engine\Execution;

use App\Audit\Engine\Contracts\Analyzer;
use JsonSerializable;

/**
 * What an {@see Analyzer}'s `run()` reports
 * back. Intentionally NOT the future `Finding` domain (Phase 3) — this is
 * intermediate, engine-level output. A real analyzer will eventually
 * normalize scanner output into `Finding` records itself and likely
 * summarize that into `rawMetadata`/`summary` here; this type doesn't
 * assume what that normalization looks like.
 *
 * Only ever Passed/Failed/TimedOut — NotApplicable/Unavailable/Skipped are
 * decided by the engine before `run()` is ever called, so an analyzer has
 * no way to report them itself (enforced by the private constructor).
 */
final readonly class AnalyzerResult implements JsonSerializable
{
    /**
     * @param  list<AnalyzerDiagnostic>  $diagnostics
     * @param  array<string,mixed>  $rawMetadata
     */
    private function __construct(
        public ExecutionStatus $status,
        public string $summary,
        public array $diagnostics = [],
        public array $rawMetadata = [],
    ) {}

    /**
     * @param  list<AnalyzerDiagnostic>  $diagnostics
     * @param  array<string,mixed>  $rawMetadata
     */
    public static function passed(string $summary, array $diagnostics = [], array $rawMetadata = []): self
    {
        return new self(ExecutionStatus::Passed, $summary, $diagnostics, $rawMetadata);
    }

    /**
     * @param  list<AnalyzerDiagnostic>  $diagnostics
     * @param  array<string,mixed>  $rawMetadata
     */
    public static function failed(string $summary, array $diagnostics = [], array $rawMetadata = []): self
    {
        return new self(ExecutionStatus::Failed, $summary, $diagnostics, $rawMetadata);
    }

    /**
     * @param  list<AnalyzerDiagnostic>  $diagnostics
     * @param  array<string,mixed>  $rawMetadata
     */
    public static function timedOut(string $summary, array $diagnostics = [], array $rawMetadata = []): self
    {
        return new self(ExecutionStatus::TimedOut, $summary, $diagnostics, $rawMetadata);
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'summary' => $this->summary,
            'diagnostics' => $this->diagnostics,
            'raw_metadata' => $this->rawMetadata,
        ];
    }
}
