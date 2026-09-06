<?php

namespace App\Audit\Analyzers\Composer;

/**
 * A successfully-parsed `composer audit --format=json` report. Reaching this
 * type at all means the JSON was structurally valid — it does NOT mean the
 * audit was informative: {@see $hasUnreachableRepositories} must still be
 * checked before trusting {@see $advisories} as an exhaustive result (see
 * ComposerAuditAnalyzer's "fail closed on network/tool failure" handling).
 */
final readonly class ComposerAuditReport
{
    /**
     * @param  list<ComposerAdvisory>  $advisories
     * @param  array<string,string|bool|null>  $abandoned  package name => replacement package name | true (abandoned, no replacement) | null
     * @param  list<string>  $unreachableRepositories
     */
    public function __construct(
        public array $advisories,
        public array $abandoned,
        public bool $hasUnreachableRepositories,
        public array $unreachableRepositories,
    ) {}
}
