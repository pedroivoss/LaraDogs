<?php

namespace App\Audit\Analyzers\Semgrep;

use JsonException;

/**
 * Parses raw `semgrep scan --json` stdout into a {@see SemgrepScanReport}.
 * Deliberately separate from any `App\Audit\Engine\Process\ProcessRunner`
 * — this class never executes anything, it only interprets a string that
 * was already captured. Mirrors `App\Audit\Analyzers\Npm\NpmAuditParser`'s
 * shape: every read is defensive (an unexpected type or missing field
 * degrades to null/empty rather than crashing), and `parse()` returns
 * `null` for the whole report whenever the expected top-level shape isn't
 * present — never a partial, best-effort object.
 *
 * Schema reference (verified against the real, locally-installed `semgrep`
 * CLI, v1.176.0 — reproduced directly, never assumed from memory or
 * documentation alone; see docs/auditing/analyzers/semgrep.md):
 * top-level keys `version`, `results` (list), `errors` (list),
 * `paths: {scanned: [...], skipped: [...]}`. `paths.skipped` is only ever
 * populated when Semgrep was invoked with `--verbose` (confirmed
 * empirically: the identical scan without `--verbose` produces an empty
 * `skipped` array even though a file WAS genuinely skipped) — SemgrepAnalyzer
 * always passes `--verbose` for exactly this reason.
 *
 * ## Rule identity: why `check_id` is matched, never trusted verbatim
 *
 * Semgrep's `check_id` is NOT simply the rule's own YAML `id:` — it is
 * prefixed with a mangled form of the `--config` path's ENCLOSING
 * DIRECTORY whenever that path contains a directory component (verified
 * empirically: an absolute `--config /tmp/xyz/rules/r.yml` produced
 * `check_id: "tmp.xyz.rules.<rule-id>"`; a relative `--config rules/r.yml`
 * produced the shorter `"rules.<rule-id>"`; a BARE filename with no
 * directory component at all, invoked with that file's own directory as
 * the process's cwd, produced the clean, unprefixed rule id with no
 * transformation whatsoever). `SemgrepAnalyzer` deliberately always
 * invokes Semgrep the third way (cwd = the bundled rules directory,
 * `--config` = the bare YAML filename) specifically to get this clean
 * form — but this parser never trusts that as a guarantee either: instead
 * of assuming a fixed prefix shape, `parse()` is given the full list of
 * known rule ids (`SemgrepRuleCatalog::ruleIds()`) and matches each
 * `check_id` by exact equality OR by ending in `.<known-rule-id>` — safe
 * regardless of which prefixing form actually occurred, and a `check_id`
 * matching NO known rule id is dropped rather than guessed at (this
 * should never happen given LaraDogs' own controlled, local-only config,
 * but "drop rather than misattribute" is the fail-closed choice if it
 * ever did).
 */
final class SemgrepParser
{
    /**
     * @param  list<string>  $knownRuleIds  See this class's own docblock —
     *                                      every `check_id` is matched
     *                                      against this list, never trusted
     *                                      as a clean rule id on its own.
     */
    public function parse(string $json, array $knownRuleIds): ?SemgrepScanReport
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        if (! is_array($decoded['results'] ?? null) || ! is_array($decoded['errors'] ?? null) || ! is_array($decoded['paths'] ?? null)) {
            return null;
        }

        $findings = [];

        foreach ($decoded['results'] as $result) {
            $finding = $this->parseResult($result, $knownRuleIds);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $errors = [];

        foreach ($decoded['errors'] as $error) {
            if (is_array($error)) {
                $errors[] = $this->normalizeError($error);
            }
        }

        $paths = $decoded['paths'];

        return new SemgrepScanReport(
            findings: $findings,
            errors: $errors,
            skipped: $this->parseSkipped($paths['skipped'] ?? null),
            scanned: is_array($paths['scanned'] ?? null) ? array_values(array_filter($paths['scanned'], 'is_string')) : [],
            semgrepVersion: is_string($decoded['version'] ?? null) ? $decoded['version'] : null,
        );
    }

    /**
     * @param  list<string>  $knownRuleIds
     */
    private function parseResult(mixed $result, array $knownRuleIds): ?SemgrepFinding
    {
        if (! is_array($result)) {
            return null;
        }

        $checkId = is_string($result['check_id'] ?? null) ? $result['check_id'] : null;
        $path = is_string($result['path'] ?? null) ? $result['path'] : null;

        if ($checkId === null || $path === null) {
            return null;
        }

        $ruleId = $this->matchKnownRuleId($checkId, $knownRuleIds);

        if ($ruleId === null) {
            // An unexpected/unrecognized rule id — never attributed to any
            // rule this analyzer's coverage claims to know about. See this
            // class's own docblock.
            return null;
        }

        $start = is_array($result['start'] ?? null) ? $result['start'] : [];
        $end = is_array($result['end'] ?? null) ? $result['end'] : [];
        $extra = is_array($result['extra'] ?? null) ? $result['extra'] : [];

        return new SemgrepFinding(
            ruleId: $ruleId,
            path: $path,
            startLine: is_int($start['line'] ?? null) ? $start['line'] : 0,
            startColumn: is_int($start['col'] ?? null) ? $start['col'] : 0,
            endLine: is_int($end['line'] ?? null) ? $end['line'] : 0,
            endColumn: is_int($end['col'] ?? null) ? $end['col'] : 0,
            message: is_string($extra['message'] ?? null) ? $extra['message'] : '',
            rawSeverity: is_string($extra['severity'] ?? null) ? $extra['severity'] : '',
            metadata: is_array($extra['metadata'] ?? null) ? $extra['metadata'] : [],
        );
    }

    /**
     * @param  list<string>  $knownRuleIds
     */
    private function matchKnownRuleId(string $checkId, array $knownRuleIds): ?string
    {
        foreach ($knownRuleIds as $candidate) {
            if ($checkId === $candidate || str_ends_with($checkId, '.'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $error
     * @return array{code: int|null, level: string, type: string, message: string, path: string|null}
     */
    private function normalizeError(array $error): array
    {
        return [
            'code' => is_int($error['code'] ?? null) ? $error['code'] : null,
            'level' => is_string($error['level'] ?? null) ? $error['level'] : 'unknown',
            'type' => $this->normalizeErrorType($error['type'] ?? null),
            'message' => is_string($error['message'] ?? null) ? $error['message'] : '',
            'path' => is_string($error['path'] ?? null) ? $error['path'] : null,
        ];
    }

    /**
     * Some error types serialize as a plain string (e.g. `"SemgrepError"`),
     * others as a `[typeName, details]` array (e.g. `PartialParsing`,
     * verified empirically to carry a list of affected file spans as its
     * second element) — only the type NAME is ever relevant to this
     * codebase's coverage-safety decision, so the details (if present) are
     * intentionally discarded here.
     */
    private function normalizeErrorType(mixed $type): string
    {
        if (is_array($type) && is_string($type[0] ?? null)) {
            return $type[0];
        }

        return is_string($type) ? $type : 'Unknown';
    }

    /**
     * @return list<array{path: string, reason: string}>
     */
    private function parseSkipped(mixed $skipped): array
    {
        if (! is_array($skipped)) {
            return [];
        }

        $result = [];

        foreach ($skipped as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $result[] = [
                    'path' => $entry['path'],
                    'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : 'unknown',
                ];
            }
        }

        return $result;
    }
}
