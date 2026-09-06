<?php

namespace App\Audit\Discovery\Inspectors;

use App\Audit\Discovery\Manifests\ComposerManifest;
use App\Audit\Discovery\Support\Detection;
use App\Audit\Discovery\Support\VersionDetection;

/**
 * Detects Composer/PHP-level facts: whether composer.json/composer.lock
 * are present and parseable, and the project's declared PHP constraint.
 * Reads only; composer.json/composer.lock are parsed as JSON data, never
 * executed and Composer itself is never invoked.
 */
final class ComposerInspector
{
    public function inspect(ComposerManifest $manifest): ComposerFindings
    {
        return new ComposerFindings(
            php: $this->inspectPhpConstraint($manifest),
            composer: $this->inspectComposerJson($manifest),
            composerLock: $this->inspectComposerLock($manifest),
        );
    }

    private function inspectComposerJson(ComposerManifest $manifest): Detection
    {
        return match (true) {
            $manifest->composerJsonValid => Detection::detected('composer.json'),
            $manifest->composerJsonExists => Detection::invalid('composer.json exists but is not valid JSON'),
            default => Detection::notDetected(),
        };
    }

    private function inspectComposerLock(ComposerManifest $manifest): Detection
    {
        return match (true) {
            $manifest->composerLockValid => Detection::detected('composer.lock'),
            $manifest->composerLockExists => Detection::invalid('composer.lock exists but is not valid JSON'),
            default => Detection::notDetected(),
        };
    }

    private function inspectPhpConstraint(ComposerManifest $manifest): VersionDetection
    {
        if (! $manifest->composerJsonValid) {
            return $manifest->composerJsonExists
                ? VersionDetection::invalid('composer.json exists but is not valid JSON')
                : VersionDetection::unknown('no composer.json present');
        }

        $constraint = $manifest->requireConstraint('php');

        return $constraint === null
            ? VersionDetection::notDetected()
            : VersionDetection::fromConstraint($constraint, 'composer.json: require.php');
    }
}
