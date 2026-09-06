<?php

namespace App\Audit\Discovery\Manifests;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Support\DiscoveryIssue;

/**
 * Safely-parsed data from a project's composer.json/composer.lock.
 *
 * Both files are read as plain JSON via {@see ProjectFilesystem} — never
 * `include`d or `require`d — so nothing in either file is ever executed.
 */
final readonly class ComposerManifest
{
    /**
     * @param  array<string,mixed>|null  $composerJson
     * @param  array<string,mixed>|null  $composerLock
     */
    private function __construct(
        public bool $composerJsonExists,
        public bool $composerJsonValid,
        public bool $composerLockExists,
        public bool $composerLockValid,
        public ?array $composerJson,
        public ?array $composerLock,
        public ?DiscoveryIssue $composerJsonIssue,
        public ?DiscoveryIssue $composerLockIssue,
    ) {}

    public static function read(ProjectFilesystem $fs): self
    {
        [$jsonExists, $json] = $fs->readJson('composer.json');
        [$lockExists, $lock] = $fs->readJson('composer.lock');

        return new self(
            composerJsonExists: $jsonExists,
            composerJsonValid: $jsonExists && $json !== null,
            composerLockExists: $lockExists,
            composerLockValid: $lockExists && $lock !== null,
            composerJson: $json,
            composerLock: $lock,
            composerJsonIssue: ($jsonExists && $json === null)
                ? new DiscoveryIssue('composer.json', 'File exists but is not valid JSON; treated as absent for detection purposes.')
                : null,
            composerLockIssue: ($lockExists && $lock === null)
                ? new DiscoveryIssue('composer.lock', 'File exists but is not valid JSON; treated as absent for version resolution.')
                : null,
        );
    }

    public function requireConstraint(string $package): ?string
    {
        $constraint = $this->composerJson['require'][$package] ?? null;

        return is_string($constraint) ? $constraint : null;
    }

    public function requireDevConstraint(string $package): ?string
    {
        $constraint = $this->composerJson['require-dev'][$package] ?? null;

        return is_string($constraint) ? $constraint : null;
    }

    public function hasRequire(string $package): bool
    {
        return $this->requireConstraint($package) !== null;
    }

    public function hasRequireDev(string $package): bool
    {
        return $this->requireDevConstraint($package) !== null;
    }

    /**
     * The version actually locked for a package, from composer.lock's
     * `packages`/`packages-dev` entries — the closest thing to "what's
     * really installed" available without running Composer.
     */
    public function lockedVersion(string $package): ?string
    {
        if ($this->composerLock === null) {
            return null;
        }

        foreach (['packages', 'packages-dev'] as $group) {
            /** @var array<int,array<string,mixed>> $entries */
            $entries = $this->composerLock[$group] ?? [];

            foreach ($entries as $entry) {
                if (($entry['name'] ?? null) === $package) {
                    $version = $entry['version'] ?? null;

                    return is_string($version) ? ltrim($version, 'v') : null;
                }
            }
        }

        return null;
    }

    /**
     * @return list<DiscoveryIssue>
     */
    public function issues(): array
    {
        return array_values(array_filter([$this->composerJsonIssue, $this->composerLockIssue]));
    }
}
