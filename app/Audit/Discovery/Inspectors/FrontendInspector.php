<?php

namespace App\Audit\Discovery\Inspectors;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Manifests\NpmManifest;
use App\Audit\Discovery\Profile\FrontendProfile;
use App\Audit\Discovery\Support\Detection;

/**
 * Detects frontend stack facts from package.json plus a small, fixed set
 * of well-known config file names (vite.config.*, tsconfig.json,
 * tailwind.config.*). package.json's `dependencies`/`devDependencies` are
 * read as data; its `scripts` are never invoked, and no `npm`/`npx`/`node`
 * process is ever spawned.
 */
final class FrontendInspector
{
    public function inspect(ProjectFilesystem $fs, NpmManifest $manifest): FrontendProfile
    {
        return new FrontendProfile(
            node: $this->inspectNode($manifest),
            packageManager: $manifest->packageManager,
            vite: $this->inspectDependencyOrConfig($manifest, $fs, 'vite', ['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.mts']),
            react: $this->inspectDependency($manifest, 'react'),
            vue: $this->inspectDependency($manifest, 'vue'),
            typescript: $this->inspectDependencyOrConfig($manifest, $fs, 'typescript', ['tsconfig.json']),
            tailwind: $this->inspectDependencyOrConfig($manifest, $fs, 'tailwindcss', ['tailwind.config.js', 'tailwind.config.ts', 'tailwind.config.cjs']),
            inertiaClient: $this->inspectInertiaClient($manifest),
        );
    }

    private function inspectNode(NpmManifest $manifest): Detection
    {
        return match (true) {
            $manifest->packageJsonValid => Detection::detected('package.json'),
            $manifest->packageJsonExists => Detection::invalid('package.json exists but is not valid JSON'),
            default => Detection::notDetected(),
        };
    }

    private function inspectDependency(NpmManifest $manifest, string $package): Detection
    {
        if (! $manifest->packageJsonValid) {
            return $manifest->packageJsonExists
                ? Detection::invalid('package.json exists but is not valid JSON')
                : Detection::unknown('no package.json present');
        }

        $section = $manifest->dependencySection($package);

        return $section !== null
            ? Detection::detected("package.json: {$section}.{$package}")
            : Detection::notDetected();
    }

    /**
     * @param  list<string>  $configGlobs
     */
    private function inspectDependencyOrConfig(NpmManifest $manifest, ProjectFilesystem $fs, string $package, array $configGlobs): Detection
    {
        $dependency = $this->inspectDependency($manifest, $package);

        if ($dependency->isDetected()) {
            return $dependency;
        }

        $configFile = $fs->matchesAny($configGlobs);

        return $configFile !== null ? Detection::detected($configFile) : $dependency;
    }

    private function inspectInertiaClient(NpmManifest $manifest): Detection
    {
        if (! $manifest->packageJsonValid) {
            return $manifest->packageJsonExists
                ? Detection::invalid('package.json exists but is not valid JSON')
                : Detection::unknown('no package.json present');
        }

        foreach (['@inertiajs/react', '@inertiajs/vue3', '@inertiajs/svelte'] as $package) {
            $section = $manifest->dependencySection($package);

            if ($section !== null) {
                return Detection::detected("package.json: {$section}.{$package}");
            }
        }

        return Detection::notDetected();
    }
}
