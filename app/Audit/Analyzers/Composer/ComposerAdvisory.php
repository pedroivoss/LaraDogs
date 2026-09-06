<?php

namespace App\Audit\Analyzers\Composer;

/**
 * One advisory entry from `composer audit --format=json`'s `advisories` map,
 * as produced by Composer\Advisory\SecurityAdvisory (verified against the
 * `composer/composer` source — see docs/auditing/analyzers/composer-audit.md).
 *
 * Every field here is read defensively by {@see ComposerAuditParser} — a
 * missing/wrong-typed field never crashes the parser, it just falls back to
 * an empty/null value, since Composer's own schema documents `cve`, `link`,
 * `severity` and `reportedAt` as nullable and `sources`'s exact shape is not
 * fully pinned down upstream.
 */
final readonly class ComposerAdvisory
{
    /**
     * @param  list<array<string,mixed>>  $sources
     */
    public function __construct(
        public string $packageName,
        public string $advisoryId,
        public string $title,
        public string $affectedVersions,
        public ?string $cve,
        public ?string $link,
        public ?string $severity,
        public ?string $reportedAt,
        public array $sources,
    ) {}
}
