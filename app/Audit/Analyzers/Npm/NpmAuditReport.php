<?php

namespace App\Audit\Analyzers\Npm;

/**
 * A successfully-parsed `npm audit --json` report. Reaching this type at
 * all means the JSON had the expected top-level shape
 * (`auditReportVersion`/`vulnerabilities`/`metadata.vulnerabilities`) —
 * see {@see NpmAuditParser} for what that rules out (network/registry
 * error responses and "no lockfile" errors have a completely different
 * shape and never reach this type).
 */
final readonly class NpmAuditReport
{
    /**
     * @param  list<NpmAdvisory>  $advisories
     * @param  array<string,int>  $severityCounts  metadata.vulnerabilities: info/low/moderate/high/critical/total
     */
    public function __construct(
        public array $advisories,
        public array $severityCounts,
    ) {}
}
