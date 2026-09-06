<?php

namespace App\Audit\Analyzers\Composer;

/**
 * Parses raw `composer audit --format=json` stdout into a {@see
 * ComposerAuditReport}. Deliberately separate from any {@see
 * \App\Audit\Engine\Process\ProcessRunner} — this class never executes
 * anything, it only interprets a string that was already captured.
 *
 * Schema reference (verified against the `composer/composer` source, not
 * assumed — see docs/auditing/analyzers/composer-audit.md):
 * `Composer\Command\AuditCommand` / `Composer\Advisory\Auditor::auditJson()`.
 * Top-level keys: `advisories` (package => list of advisory objects, always
 * present), `abandoned` (package => replacement|true|null, always present),
 * `unreachable-repositories` (present only when non-empty — this is the
 * network/tool-failure signal), `ignored-advisories` / `filter` (not
 * currently consumed by this analyzer).
 *
 * Every read here is defensive: an unexpected type, a missing field, or
 * fully invalid JSON never throws — it degrades to `null` (whole-report
 * parse failure, handled by the analyzer as "could not interpret output",
 * never as "no vulnerabilities") or to an empty/omitted value for the one
 * field affected, so a future Composer schema drift doesn't crash LaraDogs.
 */
final class ComposerAuditParser
{
    public function parse(string $json): ?ComposerAuditReport
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $rawAdvisories = $decoded['advisories'] ?? [];

        if (! is_array($rawAdvisories)) {
            return null;
        }

        $advisories = [];

        foreach ($rawAdvisories as $packageName => $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $advisory = $this->parseAdvisory($entry, (string) $packageName);

                if ($advisory !== null) {
                    $advisories[] = $advisory;
                }
            }
        }

        return new ComposerAuditReport(
            advisories: $advisories,
            abandoned: $this->parseAbandoned($decoded['abandoned'] ?? []),
            hasUnreachableRepositories: $this->parseUnreachableRepositories($decoded['unreachable-repositories'] ?? []) !== [],
            unreachableRepositories: $this->parseUnreachableRepositories($decoded['unreachable-repositories'] ?? []),
        );
    }

    private function parseAdvisory(mixed $entry, string $fallbackPackageName): ?ComposerAdvisory
    {
        if (! is_array($entry)) {
            return null;
        }

        $advisoryId = $entry['advisoryId'] ?? null;
        $title = $entry['title'] ?? null;

        // advisoryId + title are treated as the minimum required identity —
        // everything else on ComposerAdvisory is nullable/defaulted.
        if (! is_string($advisoryId) || $advisoryId === '' || ! is_string($title)) {
            return null;
        }

        return new ComposerAdvisory(
            packageName: is_string($entry['packageName'] ?? null) && $entry['packageName'] !== ''
                ? $entry['packageName']
                : $fallbackPackageName,
            advisoryId: $advisoryId,
            title: $title,
            affectedVersions: is_string($entry['affectedVersions'] ?? null) ? $entry['affectedVersions'] : '',
            cve: is_string($entry['cve'] ?? null) ? $entry['cve'] : null,
            link: is_string($entry['link'] ?? null) ? $entry['link'] : null,
            severity: is_string($entry['severity'] ?? null) && $entry['severity'] !== ''
                ? strtolower($entry['severity'])
                : null,
            reportedAt: is_string($entry['reportedAt'] ?? null) ? $entry['reportedAt'] : null,
            sources: is_array($entry['sources'] ?? null)
                ? array_values(array_filter($entry['sources'], is_array(...)))
                : [],
        );
    }

    /**
     * @return array<string,string|bool|null>
     */
    private function parseAbandoned(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $abandoned = [];

        foreach ($raw as $package => $value) {
            $package = (string) $package;

            $abandoned[$package] = match (true) {
                is_string($value) => $value,
                $value === null => null,
                // Any other shape (including a bare `true`) means "abandoned,
                // replacement not a plain string" — kept as `true` rather
                // than silently dropping the package from the report.
                default => true,
            };
        }

        return $abandoned;
    }

    /**
     * @return list<string>
     */
    private function parseUnreachableRepositories(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $repositories = [];

        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $repositories[] = $entry;

                continue;
            }

            if (is_array($entry)) {
                $encoded = json_encode($entry);

                if (is_string($encoded)) {
                    $repositories[] = $encoded;
                }
            }
        }

        return $repositories;
    }
}
