<?php

namespace App\Audit\Analyzers\Semgrep;

use App\Audit\Engine\Execution\AnalyzerCoverage;

/**
 * Decides whether a completed, successfully-parsed Semgrep run is trustworthy
 * enough to declare {@see AnalyzerCoverage::explicit()}
 * — the safety-critical decision this phase exists to prove out (Semgrep is
 * the first analyzer with an actual "rules executed" universe to declare
 * Explicit coverage from at all; see docs/auditing/analyzers/semgrep.md#coverage).
 *
 * Only ever consulted AFTER `SemgrepAnalyzer::run()` has already confirmed:
 * the process completed (`ProcessResult::successful()`), was not truncated,
 * and its stdout parsed as valid JSON with the expected top-level shape
 * ({@see SemgrepParser}). Given all of that, this class asks one further,
 * narrower question: did the run ITSELF report anything that makes "every
 * loaded rule was fully verified against every first-party file" untrue?
 *
 * Deliberately conservative — an ALLOWLIST of known-benign conditions, not a
 * blocklist of known-bad ones: any `errors[]` entry at all downgrades to
 * Unknown, including ones this codebase has never seen before. A blocklist
 * would need to anticipate every future Semgrep error/warning type in
 * advance to stay safe; an allowlist only needs today's known-safe cases to
 * be correct, and fails closed (Unknown, never a false Explicit) against
 * anything it doesn't recognize — exactly the "se houver dúvida: UNKNOWN"
 * policy this phase's spec requires.
 *
 * Two `paths.skipped[].reason` values are treated as benign and do NOT
 * invalidate coverage:
 * - `wrong_language`: a file the bundled ruleset was never going to apply
 *   to in the first place (the ruleset is PHP-only) — its absence from the
 *   scanned set changes nothing about whether PHP rules were verified.
 * - `excluded_by_config`: would only appear for an exclusion pattern
 *   LaraDogs itself configured (`SemgrepAnalyzer` never passes
 *   `--exclude`/`--include` today, so this should not occur in practice —
 *   allowlisted for forward-compatibility, not because it is currently
 *   expected).
 *
 * Every other skip reason (`exceeded_size_limit`/`too_big`,
 * `analysis_failed_parser_or_internal_error`) means a first-party file that
 * SHOULD have been analyzable was not — Unknown.
 */
final class SemgrepCoverageEvaluator
{
    private const array BENIGN_SKIP_REASONS = ['wrong_language', 'excluded_by_config'];

    public function isFullyCovered(SemgrepScanReport $report): bool
    {
        if ($report->errors !== []) {
            return false;
        }

        foreach ($report->skipped as $skip) {
            if (! in_array($skip['reason'], self::BENIGN_SKIP_REASONS, true)) {
                return false;
            }
        }

        return true;
    }
}
