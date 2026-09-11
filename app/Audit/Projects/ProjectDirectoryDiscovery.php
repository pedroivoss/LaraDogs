<?php

namespace App\Audit\Projects;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Models\Audit\Project;

/**
 * Lists candidate directories under the configured project root
 * (`config('laradogs.projects.root')`, `/projects` in the documented
 * Docker profile) for the Dashboard's "Add Project" picker, and resolves a
 * user-chosen directory NAME back to its canonical, containment-checked
 * path for {@see RegisterProject} to register.
 *
 * This is deliberately NOT a file manager: it inspects only DIRECT
 * children of the root (never recurses), never executes anything found
 * there, and every candidate — including the result of {@see resolve()} —
 * is realpath-resolved and verified to still fall within the root before
 * being trusted. That containment check is what stops a symlink planted
 * under the root (or a `..`-laden / absolute name) from ever resolving to
 * a path outside it — the same defense
 * {@see ProjectFilesystem} already applies
 * inside a project being inspected, applied here one layer earlier, before
 * a path is even considered for registration.
 */
final readonly class ProjectDirectoryDiscovery
{
    public function list(): ProjectDirectoryListResult
    {
        $root = $this->realRoot();

        if ($root === null) {
            return ProjectDirectoryListResult::unavailable();
        }

        $entries = scandir($root);

        if ($entries === false) {
            return ProjectDirectoryListResult::unavailable();
        }

        $registeredPaths = Project::query()->pluck('path')->flip();

        $candidates = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $real = realpath($root.DIRECTORY_SEPARATOR.$entry);

            if ($real === false || ! is_dir($real) || ! $this->isWithinRoot($real, $root)) {
                continue;
            }

            $candidates[] = new ProjectDirectoryCandidate(
                name: $entry,
                path: $real,
                alreadyRegistered: $registeredPaths->has($real),
                looksLikeLaravel: is_file($real.'/artisan') && is_file($real.'/composer.json'),
            );
        }

        usort($candidates, fn (ProjectDirectoryCandidate $a, ProjectDirectoryCandidate $b) => strcasecmp($a->name, $b->name));

        return ProjectDirectoryListResult::ok($candidates);
    }

    /**
     * Resolves a directory NAME (never a path — a `/`, `..`, or leading
     * `.` is rejected outright, not merely sanitized) chosen from
     * {@see list()}'s own output back to its canonical, containment-
     * verified absolute path. Returns null for anything that doesn't
     * resolve to a real, still-contained directory — including a symlink
     * that escapes the root.
     */
    public function resolve(string $name): ?string
    {
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
            return null;
        }

        $root = $this->realRoot();

        if ($root === null) {
            return null;
        }

        $real = realpath($root.DIRECTORY_SEPARATOR.$name);

        if ($real === false || ! is_dir($real) || ! $this->isWithinRoot($real, $root)) {
            return null;
        }

        return $real;
    }

    private function realRoot(): ?string
    {
        $configured = (string) config('laradogs.projects.root');
        $real = realpath($configured);

        if ($real === false || ! is_dir($real) || ! is_readable($real)) {
            return null;
        }

        return $real;
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
