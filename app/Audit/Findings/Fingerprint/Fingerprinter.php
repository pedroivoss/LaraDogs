<?php

namespace App\Audit\Findings\Fingerprint;

use App\Audit\Findings\FindingCandidate;

/**
 * Computes a Finding's stable cross-scan identity from a
 * {@see FindingCandidate} — deliberately NOT file+line (line numbers shift
 * on every unrelated edit; see ADR-0003/ADR-0010).
 *
 * v1 inputs: analyzer id + rule id + normalized file path + normalized
 * code snippet. Explicitly NOT included: line numbers (identity must
 * survive code moving around), severity/confidence/title/description
 * (metadata about the issue, not the issue's identity — these can be
 * recalibrated without it becoming "a different issue"), and the project
 * (fingerprints are scoped to a project via a database unique constraint
 * on (project_id, fingerprint, fingerprint_version), not by mixing the
 * project into the hash itself — see ADR-0010).
 *
 * This is intentionally NOT semantic/AST-aware analysis — that's future
 * work if a real need for it emerges. Normalization here is a shallow,
 * deterministic text transform (whitespace collapsing, line-ending
 * normalization), not code understanding.
 *
 * Versioned (`VERSION`) so a future, smarter algorithm can be introduced
 * without silently reinterpreting or destroying existing Finding history
 * — a `fingerprint_version` bump means "these are computed by a different
 * rule," never "recompute and merge into the old identity."
 */
final class Fingerprinter
{
    public const string VERSION = 'v1';

    public function fingerprint(FindingCandidate $candidate): string
    {
        $input = implode("\x1f", [
            $candidate->analyzerId,
            $candidate->ruleId,
            $this->normalizePath($candidate->filePath),
            $this->normalizeCode($candidate->codeSnippet),
        ]);

        return hash('sha256', $input);
    }

    private function normalizePath(?string $path): string
    {
        if ($path === null) {
            return '';
        }

        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function normalizeCode(?string $code): string
    {
        if ($code === null || trim($code) === '') {
            return '';
        }

        // Normalize line endings, then collapse all whitespace runs to a
        // single space and trim — resilient to reformatting/reindentation
        // without attempting to understand the code semantically.
        $normalized = str_replace(["\r\n", "\r"], "\n", $code);

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }
}
