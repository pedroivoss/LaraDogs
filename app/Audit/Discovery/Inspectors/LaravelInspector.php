<?php

namespace App\Audit\Discovery\Inspectors;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Manifests\ComposerManifest;
use App\Audit\Discovery\Support\Detection;
use App\Audit\Discovery\Support\VersionDetection;

/**
 * Detects Laravel itself and Laravel-adjacent capabilities (Blade,
 * Livewire, Inertia's server-side package, and a short list of well-known
 * first-party packages) from composer.json/composer.lock and a bounded
 * scan of resources/views for *.blade.php files. Never executes the
 * target's `artisan` or any vendor code.
 */
final class LaravelInspector
{
    /**
     * Composer package name => short key used in the profile's package map.
     *
     * @var array<string,string>
     */
    private const array KNOWN_PACKAGES = [
        'laravel/sanctum' => 'sanctum',
        'laravel/fortify' => 'fortify',
        'laravel/jetstream' => 'jetstream',
        'laravel/breeze' => 'breeze',
        'laravel/octane' => 'octane',
        'laravel/horizon' => 'horizon',
        'laravel/telescope' => 'telescope',
        'laravel/pulse' => 'pulse',
        'laravel/reverb' => 'reverb',
        'laravel/scout' => 'scout',
        'laravel/cashier' => 'cashier',
    ];

    public function inspect(ProjectFilesystem $fs, ComposerManifest $manifest): LaravelFindings
    {
        return new LaravelFindings(
            laravel: $this->inspectLaravel($manifest),
            blade: $this->inspectBlade($fs, $manifest),
            livewire: $this->inspectComposerPackage($manifest, 'livewire/livewire'),
            inertia: $this->inspectComposerPackage($manifest, 'inertiajs/inertia-laravel'),
            packages: $this->inspectKnownPackages($manifest),
        );
    }

    private function inspectLaravel(ComposerManifest $manifest): VersionDetection
    {
        if (! $manifest->composerJsonValid) {
            return $manifest->composerJsonExists
                ? VersionDetection::invalid('composer.json exists but is not valid JSON')
                : VersionDetection::unknown('no composer.json present');
        }

        $constraint = $manifest->requireConstraint('laravel/framework');

        if ($constraint === null) {
            return VersionDetection::notDetected();
        }

        $installed = $manifest->lockedVersion('laravel/framework');

        return $installed !== null
            ? VersionDetection::fromInstalledVersion($installed, 'composer.lock: laravel/framework', $constraint)
            : VersionDetection::fromConstraint($constraint, 'composer.json: require.laravel/framework');
    }

    private function inspectBlade(ProjectFilesystem $fs, ComposerManifest $manifest): Detection
    {
        if ($fs->hasFileWithSuffixUnder('resources/views', '.blade.php')) {
            return Detection::detected('resources/views/**/*.blade.php');
        }

        if (! $manifest->composerJsonValid && $manifest->composerJsonExists) {
            return Detection::invalid('composer.json exists but is not valid JSON');
        }

        return Detection::notDetected();
    }

    private function inspectComposerPackage(ComposerManifest $manifest, string $package): Detection
    {
        if (! $manifest->composerJsonValid) {
            return $manifest->composerJsonExists
                ? Detection::invalid('composer.json exists but is not valid JSON')
                : Detection::unknown('no composer.json present');
        }

        return $manifest->hasRequire($package)
            ? Detection::detected("composer.json: require.{$package}")
            : Detection::notDetected();
    }

    /**
     * @return array<string,Detection>
     */
    private function inspectKnownPackages(ComposerManifest $manifest): array
    {
        $packages = [];

        foreach (self::KNOWN_PACKAGES as $composerName => $key) {
            $packages[$key] = $this->inspectComposerPackage($manifest, $composerName);
        }

        return $packages;
    }
}
