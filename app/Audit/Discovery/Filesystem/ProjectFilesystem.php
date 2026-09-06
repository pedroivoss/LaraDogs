<?php

namespace App\Audit\Discovery\Filesystem;

/**
 * A read-only, bounded view over a single project root.
 *
 * This is the only way Project Discovery touches the filesystem of a
 * project being inspected. It never executes anything and never follows a
 * path outside the resolved root:
 *
 * - Every relative path is resolved with `realpath()` (which collapses
 *   `..` segments and follows symlinks) and rejected if the result falls
 *   outside the root — this is what stops both path traversal and a
 *   symlink planted inside the project from escaping it.
 * - Reads are capped at `$maxReadableBytes`; anything larger is treated as
 *   unreadable rather than loaded into memory.
 * - Recursive lookups (`hasFileWithSuffixUnder`) are bounded in depth and
 *   total entries visited, so a huge or adversarially deep/wide tree can't
 *   turn discovery into a resource-exhaustion problem.
 *
 * Nothing here ever calls `include`, `require`, `eval`, `exec`, `shell_exec`,
 * or similar — files are only ever opened for their raw bytes.
 */
final class ProjectFilesystem
{
    public const int DEFAULT_MAX_READABLE_BYTES = 5_000_000;

    private readonly string $root;

    public function __construct(
        string $root,
        private readonly int $maxReadableBytes = self::DEFAULT_MAX_READABLE_BYTES,
    ) {
        // Canonicalize eagerly (e.g. macOS's /tmp -> /private/tmp) so the
        // prefix comparisons in isWithinRoot() compare like with like,
        // regardless of which path form the caller passed in.
        $this->root = realpath($root) ?: $root;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolve a project-relative path to a real, absolute path inside the
     * project root. Returns null if it doesn't exist, can't be resolved,
     * or (after following symlinks) escapes the root.
     */
    public function resolve(string $relativePath): ?string
    {
        $relativePath = ltrim($relativePath, '/\\');
        $candidate = $this->root.DIRECTORY_SEPARATOR.$relativePath;

        $real = realpath($candidate);

        if ($real === false || ! $this->isWithinRoot($real)) {
            return null;
        }

        return $real;
    }

    public function fileExists(string $relativePath): bool
    {
        $real = $this->resolve($relativePath);

        return $real !== null && is_file($real);
    }

    public function directoryExists(string $relativePath): bool
    {
        $real = $this->resolve($relativePath);

        return $real !== null && is_dir($real);
    }

    /**
     * Read a file as plain bytes, bounded by size. Returns null when the
     * file is missing, unreadable, not a regular file, or larger than the
     * configured cap — callers must treat null as "no evidence available",
     * never as "empty file".
     */
    public function readFile(string $relativePath): ?string
    {
        $real = $this->resolve($relativePath);

        if ($real === null || ! is_file($real) || ! is_readable($real)) {
            return null;
        }

        $size = filesize($real);

        if ($size === false || $size > $this->maxReadableBytes) {
            return null;
        }

        $contents = file_get_contents($real);

        return $contents === false ? null : $contents;
    }

    /**
     * Decode a JSON file into an associative array.
     *
     * @return array{0: bool, 1: array<mixed>|null} Tuple of [file existed,
     *                                              decoded array or null].
     *                                              A true/null pair means
     *                                              the file exists but is
     *                                              not valid JSON — the
     *                                              caller should treat
     *                                              that as "malformed",
     *                                              distinct from "absent".
     */
    public function readJson(string $relativePath): array
    {
        $contents = $this->readFile($relativePath);

        if ($contents === null) {
            return [false, null];
        }

        $decoded = json_decode($contents, associative: true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return [true, null];
        }

        /** @var array<mixed> $decoded */
        return [true, $decoded];
    }

    /**
     * Return the first relative path (within the root) matching any of the
     * given glob patterns, or null if none match. Used for shallow "does a
     * file like this exist" checks (e.g. `vite.config.*`) without an
     * unbounded filesystem walk.
     *
     * @param  list<string>  $patterns
     */
    public function matchesAny(array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            $matches = glob($this->root.DIRECTORY_SEPARATOR.$pattern, GLOB_NOSORT) ?: [];

            foreach ($matches as $match) {
                $real = realpath($match);

                if ($real !== false && $this->isWithinRoot($real) && is_file($real)) {
                    return ltrim(substr($real, strlen($this->root)), DIRECTORY_SEPARATOR);
                }
            }
        }

        return null;
    }

    /**
     * Bounded recursive existence check for a filename suffix (e.g.
     * ".blade.php") under a given relative directory.
     */
    public function hasFileWithSuffixUnder(
        string $relativeDirectory,
        string $suffix,
        int $maxDepth = 4,
        int $maxEntriesVisited = 2000,
    ): bool {
        $real = $this->resolve($relativeDirectory);

        if ($real === null || ! is_dir($real)) {
            return false;
        }

        $visited = 0;

        return $this->walk($real, $suffix, $maxDepth, $maxEntriesVisited, $visited);
    }

    private function walk(string $directory, string $suffix, int $depthRemaining, int $maxEntriesVisited, int &$visited): bool
    {
        if ($depthRemaining < 0) {
            return false;
        }

        $entries = @scandir($directory) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (++$visited > $maxEntriesVisited) {
                return false;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            $real = realpath($path);

            if ($real === false || ! $this->isWithinRoot($real)) {
                continue;
            }

            if (is_dir($real)) {
                if ($this->walk($real, $suffix, $depthRemaining - 1, $maxEntriesVisited, $visited)) {
                    return true;
                }

                continue;
            }

            if (is_file($real) && str_ends_with($entry, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isWithinRoot(string $real): bool
    {
        return $real === $this->root || str_starts_with($real, $this->root.DIRECTORY_SEPARATOR);
    }
}
