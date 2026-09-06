<?php

namespace App\Audit\Discovery\Inspectors;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Manifests\ComposerManifest;
use App\Audit\Discovery\Manifests\NpmManifest;
use App\Audit\Discovery\Profile\TestingProfile;
use App\Audit\Discovery\Support\Detection;

/**
 * Detects which testing tools a project declares, via composer/npm
 * manifests and a small set of well-known config file names. Nothing is
 * ever run — Pest/PHPUnit/Playwright/Vitest/Jest/Cypress binaries are
 * never invoked here.
 */
final class TestingInspector
{
    public function inspect(ProjectFilesystem $fs, ComposerManifest $composer, NpmManifest $npm): TestingProfile
    {
        return new TestingProfile(
            pest: $this->inspectComposerOrFile($composer, $fs, 'pestphp/pest', ['pest.php', 'tests/Pest.php']),
            phpunit: $this->inspectComposerOrFile($composer, $fs, 'phpunit/phpunit', ['phpunit.xml', 'phpunit.xml.dist']),
            playwright: $this->inspectNpmOrFile($npm, $fs, '@playwright/test', ['playwright.config.js', 'playwright.config.ts']),
            vitest: $this->inspectNpmOrFile($npm, $fs, 'vitest', ['vitest.config.js', 'vitest.config.ts']),
            jest: $this->inspectNpmOrFile($npm, $fs, 'jest', ['jest.config.js', 'jest.config.ts', 'jest.config.cjs']),
            cypress: $this->inspectNpmOrFile($npm, $fs, 'cypress', ['cypress.config.js', 'cypress.config.ts']),
        );
    }

    /**
     * @param  list<string>  $files
     */
    private function inspectComposerOrFile(ComposerManifest $manifest, ProjectFilesystem $fs, string $package, array $files): Detection
    {
        if ($manifest->composerJsonValid && ($manifest->hasRequireDev($package) || $manifest->hasRequire($package))) {
            return Detection::detected("composer.json: {$package}");
        }

        foreach ($files as $file) {
            if ($fs->fileExists($file)) {
                return Detection::detected($file);
            }
        }

        return Detection::notDetected();
    }

    /**
     * @param  list<string>  $files
     */
    private function inspectNpmOrFile(NpmManifest $manifest, ProjectFilesystem $fs, string $package, array $files): Detection
    {
        if ($manifest->packageJsonValid && $manifest->hasDependency($package)) {
            return Detection::detected("package.json: {$package}");
        }

        foreach ($files as $file) {
            if ($fs->fileExists($file)) {
                return Detection::detected($file);
            }
        }

        return Detection::notDetected();
    }
}
