<?php

namespace App\Audit\Analyzers\Semgrep;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves the `semgrep` executable LaraDogs itself will invoke — NEVER
 * something read from or influenced by the audited target. Mirrors
 * `App\Audit\Analyzers\Composer\ComposerBinaryResolver` and
 * `App\Audit\Analyzers\Npm\NpmBinaryResolver`'s shape exactly (small,
 * deliberate duplication rather than a shared abstraction — see those
 * classes' own docblocks for why). Two sources only:
 *
 * 1. `config('laradogs.semgrep.binary')`, an explicit operator override
 *    (LaraDogs' own config/env, never the target's `.env`);
 * 2. LaraDogs' own PATH, searched via Symfony's {@see ExecutableFinder}.
 *
 * Never a binary living inside the target's own `.venv`/`node_modules`/
 * `vendor`, and never a path/name read from any target project file —
 * both would let the audited project choose what LaraDogs executes.
 *
 * A resolved path is a plain "this file exists and looks executable"
 * fact — it is deliberately NOT proof that the binary is actually Semgrep
 * or that it satisfies the minimum supported version;
 * {@see SemgrepAnalyzer::availability()} still has to run a real
 * `--version` check before this analyzer is considered available.
 */
final class SemgrepBinaryResolver
{
    public function __construct(
        private readonly ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    public function resolve(): ?string
    {
        $configured = config('laradogs.semgrep.binary');

        if (is_string($configured) && $configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return $this->finder->find('semgrep');
    }
}
