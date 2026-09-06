<?php

namespace App\Audit\Discovery\Manifests;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Profile\PackageManager;
use App\Audit\Discovery\Support\DiscoveryIssue;

/**
 * Safely-parsed data from a project's package.json plus the lockfile it
 * ships (which tells us the package manager without ever invoking one).
 *
 * package.json is read as plain JSON via {@see ProjectFilesystem} — its
 * `scripts` are inspected as data only and are never executed.
 */
final readonly class NpmManifest
{
    /**
     * @param  array<string,mixed>|null  $packageJson
     */
    private function __construct(
        public bool $packageJsonExists,
        public bool $packageJsonValid,
        public ?array $packageJson,
        public ?PackageManager $packageManager,
        public ?DiscoveryIssue $packageJsonIssue,
    ) {}

    public static function read(ProjectFilesystem $fs): self
    {
        [$exists, $json] = $fs->readJson('package.json');

        return new self(
            packageJsonExists: $exists,
            packageJsonValid: $exists && $json !== null,
            packageJson: $json,
            packageManager: self::detectPackageManager($fs),
            packageJsonIssue: ($exists && $json === null)
                ? new DiscoveryIssue('package.json', 'File exists but is not valid JSON; treated as absent for detection purposes.')
                : null,
        );
    }

    private static function detectPackageManager(ProjectFilesystem $fs): ?PackageManager
    {
        return match (true) {
            $fs->fileExists('pnpm-lock.yaml') => PackageManager::Pnpm,
            $fs->fileExists('yarn.lock') => PackageManager::Yarn,
            $fs->fileExists('package-lock.json') => PackageManager::Npm,
            default => null,
        };
    }

    public function dependency(string $package): ?string
    {
        $version = $this->packageJson['dependencies'][$package]
            ?? $this->packageJson['devDependencies'][$package]
            ?? null;

        return is_string($version) ? $version : null;
    }

    public function hasDependency(string $package): bool
    {
        return $this->dependency($package) !== null;
    }

    /**
     * Which manifest section actually declares the package, for accurate
     * evidence strings — 'dependencies' and 'devDependencies' are
     * different signals (e.g. a build tool vs. a runtime dependency).
     */
    public function dependencySection(string $package): ?string
    {
        return match (true) {
            isset($this->packageJson['dependencies'][$package]) => 'dependencies',
            isset($this->packageJson['devDependencies'][$package]) => 'devDependencies',
            default => null,
        };
    }

    /**
     * @return list<DiscoveryIssue>
     */
    public function issues(): array
    {
        return $this->packageJsonIssue !== null ? [$this->packageJsonIssue] : [];
    }
}
