<?php

namespace App\Audit\Analyzers\Npm;

/**
 * Parses raw `npm audit --json` stdout into an {@see NpmAuditReport}.
 * Deliberately separate from any
 * `App\Audit\Engine\Process\ProcessRunner` — this class never
 * executes anything, it only interprets a string that was already
 * captured.
 *
 * Schema reference (verified against the real npm CLI, v10.9.7,
 * `auditReportVersion: 2` — reproduced locally, not assumed from memory
 * or documentation alone; see docs/auditing/analyzers/npm-audit.md):
 * top-level keys `auditReportVersion`, `vulnerabilities` (map of package
 * name => vulnerability object), `metadata` (`vulnerabilities`: severity
 * counts; `dependencies`: dependency counts).
 *
 * A network/registry failure produces a COMPLETELY different top-level
 * shape (`{"message": "...", "error": {...}}`, no `vulnerabilities`/
 * `metadata`/`auditReportVersion` keys), reproduced by pointing a real
 * npm audit at an unreachable registry. A missing-lockfile error is
 * different again (`{"error": {"code": "ENOLOCK", ...}}`). Both are
 * indistinguishable from any other structurally-wrong JSON as far as this
 * parser is concerned: `parse()` returns `null` for the whole report
 * whenever the expected shape isn't present, so both failure modes are
 * handled the same, safe way by the caller (never treated as clean).
 *
 * Every read here is defensive: an unexpected type or a missing field on
 * any single key degrades to null/empty/false rather than crashing.
 */
final class NpmAuditParser
{
    public function parse(string $json): ?NpmAuditReport
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        if (! array_key_exists('auditReportVersion', $decoded)) {
            return null;
        }

        $rawVulnerabilities = $decoded['vulnerabilities'] ?? null;

        if (! is_array($rawVulnerabilities)) {
            return null;
        }

        $severityCounts = $this->parseSeverityCounts($decoded['metadata'] ?? null);

        if ($severityCounts === null) {
            return null;
        }

        $advisories = [];

        foreach ($rawVulnerabilities as $packageName => $entry) {
            foreach ($this->parseEntry((string) $packageName, $entry) as $advisory) {
                $advisories[] = $advisory;
            }
        }

        return new NpmAuditReport(advisories: $advisories, severityCounts: $severityCounts);
    }

    /**
     * @return array<string,int>|null
     */
    private function parseSeverityCounts(mixed $metadata): ?array
    {
        if (! is_array($metadata) || ! is_array($metadata['vulnerabilities'] ?? null)) {
            return null;
        }

        $counts = [];

        foreach ($metadata['vulnerabilities'] as $severity => $count) {
            if (is_string($severity) && is_int($count)) {
                $counts[$severity] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return list<NpmAdvisory>
     */
    private function parseEntry(string $fallbackPackageName, mixed $entry): array
    {
        if (! is_array($entry)) {
            return [];
        }

        $packageName = is_string($entry['name'] ?? null) && $entry['name'] !== ''
            ? $entry['name']
            : $fallbackPackageName;

        $isDirect = (bool) ($entry['isDirect'] ?? false);
        $packageRange = is_string($entry['range'] ?? null) ? $entry['range'] : null;
        $fixAvailable = $this->parseFixAvailable($entry['fixAvailable'] ?? false);
        $nodes = is_array($entry['nodes'] ?? null)
            ? array_values(array_filter($entry['nodes'], 'is_string'))
            : [];

        $via = is_array($entry['via'] ?? null) ? $entry['via'] : [];

        $advisories = [];

        foreach ($via as $viaEntry) {
            // Plain strings are meta-vulnerability cross-references to
            // another package's own entry in the map — never a real
            // advisory on their own. Only object entries (identified by a
            // `source` field) carry real advisory data.
            if (! is_array($viaEntry) || ! array_key_exists('source', $viaEntry) || ! is_int($viaEntry['source'])) {
                continue;
            }

            $advisories[] = new NpmAdvisory(
                packageName: $packageName,
                isDirect: $isDirect,
                source: $viaEntry['source'],
                title: is_string($viaEntry['title'] ?? null) ? $viaEntry['title'] : sprintf('Security advisory for %s', $packageName),
                url: is_string($viaEntry['url'] ?? null) ? $viaEntry['url'] : null,
                severity: is_string($viaEntry['severity'] ?? null) && $viaEntry['severity'] !== ''
                    ? strtolower($viaEntry['severity'])
                    : null,
                cwe: is_array($viaEntry['cwe'] ?? null)
                    ? array_values(array_filter($viaEntry['cwe'], 'is_string'))
                    : [],
                range: is_string($viaEntry['range'] ?? null) ? $viaEntry['range'] : null,
                packageRange: $packageRange,
                fixAvailable: $fixAvailable,
                nodes: $nodes,
            );
        }

        return $advisories;
    }

    /**
     * @return bool|array{name: string|null, version: string|null, is_semver_major: bool}
     */
    private function parseFixAvailable(mixed $raw): bool|array
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            return [
                'name' => is_string($raw['name'] ?? null) ? $raw['name'] : null,
                'version' => is_string($raw['version'] ?? null) ? $raw['version'] : null,
                'is_semver_major' => (bool) ($raw['isSemVerMajor'] ?? false),
            ];
        }

        return false;
    }
}
