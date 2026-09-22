<?php

namespace App\Audit\Source;

/**
 * The verdict on whether an audit's source can support ABSENCE-based
 * conclusions (auto-resolving findings, Quality Gate passes).
 *
 * - `consistent === true`  — a clean Git repository with a commit that was
 *   identical before and after the analyzers (the only "verified" state).
 * - `consistent === false` — integrity NOT established; {@see $reason} says why.
 * - `consistent === null`  — a genuine non-Git target (no source-integrity
 *   claim exists to make) or a source that was never captured (legacy).
 */
final readonly class SourceIntegrity
{
    private function __construct(
        public ?bool $consistent,
        public ?SourceIntegrityReason $reason,
    ) {}

    public static function verified(): self
    {
        return new self(true, null);
    }

    public static function notApplicable(): self
    {
        return new self(null, null);
    }

    public static function unverified(SourceIntegrityReason $reason): self
    {
        return new self(false, $reason);
    }
}
