<?php

namespace App\Audit\Analyzers\Semgrep;

/**
 * LaraDogs' OWN bounded, read-only file walk over a project root, used to
 * build the EXPLICIT list of file paths handed to `semgrep scan` as
 * targets — mirrors `App\Audit\Discovery\Filesystem\ProjectFilesystem`'s
 * realpath-containment philosophy (a separate class, not a reuse of that
 * one: this walker collects a bounded LIST of files rather than answering
 * one existence question, and lives in the outer `Analyzers` namespace
 * that Discovery's own classes must stay free of).
 *
 * This exists to close a real, empirically-reproduced trust gap (see
 * docs/auditing/analyzers/semgrep.md#rule-source-and-ignore-policy-trust):
 * pointing `semgrep scan` directly at a DIRECTORY lets the audited
 * project's own `.semgrepignore`/`.gitignore` decide what gets analyzed —
 * reproduced directly with a `.semgrepignore` entry that made a real,
 * genuinely vulnerable file invisible to a scan. Passing an EXPLICIT file
 * list instead (built by LaraDogs' own walk, never consulting either
 * ignore file) bypasses that mechanism entirely — verified empirically
 * that Semgrep scans an explicitly-named target file regardless of what
 * `.semgrepignore` says about it.
 *
 * Safety properties, each deliberate:
 * - **Symlinks are rejected outright**, before ever being resolved
 *   (`is_link()`, checked first) — defense in depth. Semgrep itself
 *   independently refuses to scan a symlink passed as a target (verified
 *   empirically: it surfaces a JSON `errors` entry rather than following
 *   or silently skipping it), but this walker never offers one in the
 *   first place, avoiding both the risk and the resulting noisy error.
 * - **Every resolved path is `realpath()`-contained inside the project
 *   root** — the same traversal protection `ProjectFilesystem` already
 *   uses, applied here independently since this class never touches that
 *   one (staying in the outer `Analyzers` namespace, per ADR-0011).
 * - **A fixed, LaraDogs-controlled set of directories is always excluded**
 *   (`vendor`, `node_modules`, `storage`, `bootstrap/cache`,
 *   `public/build`, `dist`, `coverage`, `.git`) — chosen from real evidence
 *   (these are exactly the directories a target's build/dependency
 *   tooling populates, never first-party source), and enforced by
 *   LaraDogs' own code rather than trusting the target's `.gitignore` to
 *   list them (a target's `.gitignore` is untrusted input for exactly the
 *   same reason `.semgrepignore` is).
 * - **Depth, total-entries-visited, and total-files-collected are all
 *   bounded**, so a huge or adversarially deep/wide tree can't turn target
 *   collection into a resource-exhaustion problem — mirroring
 *   `ProjectFilesystem::hasFileWithSuffixUnder()`'s own bounded walk.
 *
 * Only PHP files are collected (`.php` extension) — the only language the
 * bundled ruleset (`SemgrepRuleCatalog`) declares rules for this phase; see
 * docs/auditing/static-analysis.md for why this is deliberately narrow
 * rather than a generic multi-language walk.
 */
final class SemgrepTargetCollector
{
    private const array DEFAULT_EXCLUDED_DIRECTORIES = [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        'public/build',
        'dist',
        'coverage',
        '.git',
    ];

    public function __construct(
        private readonly int $maxDepth = 25,
        private readonly int $maxEntriesVisited = 50_000,
        private readonly int $maxFiles = 5_000,
    ) {}

    /**
     * @return list<string> absolute, realpath-resolved file paths — every
     *                      one guaranteed to be a regular file (never a
     *                      symlink), contained within $root, outside every
     *                      excluded directory, and matching $extension
     */
    public function collect(string $root, string $extension = '.php'): array
    {
        $realRoot = realpath($root);

        if ($realRoot === false || ! is_dir($realRoot)) {
            return [];
        }

        $files = [];
        $visited = 0;

        $this->walk($realRoot, $realRoot, $extension, $this->maxDepth, $visited, $files);

        return $files;
    }

    /**
     * @param  list<string>  $files
     */
    private function walk(string $realRoot, string $directory, string $extension, int $depthRemaining, int &$visited, array &$files): void
    {
        if ($depthRemaining < 0 || count($files) >= $this->maxFiles) {
            return;
        }

        $entries = @scandir($directory) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (++$visited > $this->maxEntriesVisited || count($files) >= $this->maxFiles) {
                return;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            // Rejected BEFORE realpath() ever resolves it — see this
            // class's own docblock.
            if (is_link($path)) {
                continue;
            }

            $real = realpath($path);

            if ($real === false || ! $this->isWithinRoot($realRoot, $real)) {
                continue;
            }

            if (is_dir($real)) {
                if ($this->isExcludedDirectory($realRoot, $real)) {
                    continue;
                }

                $this->walk($realRoot, $real, $extension, $depthRemaining - 1, $visited, $files);

                continue;
            }

            if (is_file($real) && str_ends_with($entry, $extension)) {
                $files[] = $real;
            }
        }
    }

    private function isExcludedDirectory(string $realRoot, string $real): bool
    {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($real, strlen($realRoot)), DIRECTORY_SEPARATOR));

        foreach (self::DEFAULT_EXCLUDED_DIRECTORIES as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isWithinRoot(string $root, string $real): bool
    {
        return $real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR);
    }
}
