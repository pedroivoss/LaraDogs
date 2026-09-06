<?php

namespace App\Audit\Discovery\Profile;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Inspectors\ComposerFindings;
use App\Audit\Discovery\Inspectors\LaravelFindings;
use App\Audit\Discovery\Manifests\ComposerManifest;
use App\Audit\Discovery\Manifests\NpmManifest;
use App\Audit\Discovery\Support\DetectionStatus;

/**
 * Assembles the per-section inspector output into a single
 * {@see ProjectProfile} and resolves the overall {@see ProjectType}.
 */
final class ProfileBuilder
{
    public function build(
        string $path,
        ProjectFilesystem $fs,
        ComposerManifest $composerManifest,
        NpmManifest $npmManifest,
        ComposerFindings $composer,
        LaravelFindings $laravel,
        FrontendProfile $frontend,
        TestingProfile $testing,
        InfrastructureProfile $infrastructure,
        DatabaseProfile $database,
    ): ProjectProfile {
        $backend = new BackendProfile(
            php: $composer->php,
            composer: $composer->composer,
            composerLock: $composer->composerLock,
            laravel: $laravel->laravel,
            blade: $laravel->blade,
            livewire: $laravel->livewire,
            inertia: $laravel->inertia,
            packages: $laravel->packages,
        );

        return new ProjectProfile(
            path: $path,
            type: $this->resolveType($fs, $composerManifest, $npmManifest, $laravel),
            backend: $backend,
            frontend: $frontend,
            testing: $testing,
            infrastructure: $infrastructure,
            database: $database,
            issues: [...$composerManifest->issues(), ...$npmManifest->issues()],
        );
    }

    private function resolveType(
        ProjectFilesystem $fs,
        ComposerManifest $composerManifest,
        NpmManifest $npmManifest,
        LaravelFindings $laravel,
    ): ProjectType {
        if ($composerManifest->composerJsonValid) {
            return $laravel->laravel->status === DetectionStatus::Detected
                ? ProjectType::Laravel
                : ProjectType::PlainPhpComposer;
        }

        if ($npmManifest->packageJsonValid) {
            return ProjectType::NodeOnly;
        }

        return $this->looksEmpty($fs, $composerManifest, $npmManifest)
            ? ProjectType::EmptyProject
            : ProjectType::Unknown;
    }

    /**
     * A directory counts as empty only when neither manifest exists at all
     * (valid or malformed) and it has no other visible entries — a
     * malformed manifest means "unclassifiable", not "empty", and a
     * directory holding unrelated files (but no manifest) is Unknown, not
     * Empty.
     */
    private function looksEmpty(ProjectFilesystem $fs, ComposerManifest $composerManifest, NpmManifest $npmManifest): bool
    {
        if ($composerManifest->composerJsonExists || $npmManifest->packageJsonExists) {
            return false;
        }

        $entries = @scandir($fs->root()) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            return false;
        }

        return true;
    }
}
