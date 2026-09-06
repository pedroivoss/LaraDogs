<?php

namespace App\Audit\Analyzers\Composer;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves the `composer` executable LaraDogs itself will invoke — NEVER
 * something read from or influenced by the audited target. Two sources only:
 *
 * 1. `config('laradogs.composer.binary')`, an explicit operator override
 *    (LaraDogs' own config/env, never the target's `.env`);
 * 2. LaraDogs' own PATH, searched via Symfony's {@see ExecutableFinder}.
 *
 * A resolved path is a plain "this file exists and looks executable" fact —
 * it is deliberately NOT proof that the binary is actually Composer or that
 * it satisfies the minimum supported version; {@see
 * \App\Audit\Analyzers\Composer\ComposerAuditAnalyzer::availability()} still
 * has to run a real `--version` check before this analyzer is considered
 * available.
 */
final class ComposerBinaryResolver
{
    public function __construct(
        private readonly ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    public function resolve(): ?string
    {
        $configured = config('laradogs.composer.binary');

        if (is_string($configured) && $configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return $this->finder->find('composer');
    }
}
