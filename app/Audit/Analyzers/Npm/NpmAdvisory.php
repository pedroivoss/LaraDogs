<?php

namespace App\Audit\Analyzers\Npm;

/**
 * One real advisory entry from `npm audit --json`'s
 * `vulnerabilities.<package>.via` array — verified against the actual npm
 * CLI (v10.9.7, `auditReportVersion: 2`), not assumed. See
 * docs/auditing/analyzers/npm-audit.md.
 *
 * `via` is a MIXED array: real advisory objects (which have a `source`
 * field) interleaved with plain package-name STRINGS (meta-vulnerability
 * cross-references to another entry in the same `vulnerabilities` map —
 * e.g. `mocha`'s `via` can be entirely strings like `["debug", "mkdirp"]`
 * when `mocha` itself carries no direct advisory and is only vulnerable
 * because of what it depends on). {@see NpmAuditParser} only ever
 * constructs this type from the OBJECT entries; the string entries are
 * deliberately skipped — they reference a package that already gets its
 * own `NpmAdvisory` entries from its own `via` array, so following the
 * string cross-references here would only produce duplicate/impossible
 * candidates.
 */
final readonly class NpmAdvisory
{
    /**
     * @param  list<string>  $cwe
     * @param  bool|array{name: string|null, version: string|null, is_semver_major: bool}  $fixAvailable
     * @param  list<string>  $nodes
     */
    public function __construct(
        public string $packageName,
        public bool $isDirect,
        public int $source,
        public string $title,
        public ?string $url,
        public ?string $severity,
        public array $cwe,
        public ?string $range,
        public ?string $packageRange,
        public bool|array $fixAvailable,
        public array $nodes,
    ) {}
}
