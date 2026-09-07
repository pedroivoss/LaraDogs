<?php

namespace App\Audit\Analyzers\Npm;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves the `npm` executable LaraDogs itself will invoke — NEVER
 * something read from or influenced by the audited target. Two sources
 * only, mirroring `App\Audit\Analyzers\Composer\ComposerBinaryResolver`'s
 * shape (a small, deliberate duplication rather than a premature shared
 * abstraction — see docs/auditing/analyzers/npm-audit.md):
 *
 * 1. `config('laradogs.npm.binary')`, an explicit operator override
 *    (LaraDogs' own config/env, never the target's `.env`);
 * 2. LaraDogs' own PATH, searched via Symfony's {@see ExecutableFinder}.
 *
 * Never `./node_modules/.bin/npm` from the target, and never a binary
 * name/path read from the target's `package.json` — both would let the
 * audited project choose what LaraDogs executes.
 *
 * A resolved path is a plain "this file exists and looks executable"
 * fact — it is deliberately NOT proof that the binary is actually npm or
 * that it satisfies the minimum supported version; {@see
 * \App\Audit\Analyzers\Npm\NpmAuditAnalyzer::availability()} still has to
 * run a real `--version` check before this analyzer is considered
 * available.
 */
final class NpmBinaryResolver
{
    public function __construct(
        private readonly ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    public function resolve(): ?string
    {
        $configured = config('laradogs.npm.binary');

        if (is_string($configured) && $configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return $this->finder->find('npm');
    }
}
