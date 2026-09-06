<?php

namespace App\Audit\Discovery;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Inspectors\ComposerInspector;
use App\Audit\Discovery\Inspectors\DatabaseInspector;
use App\Audit\Discovery\Inspectors\FrontendInspector;
use App\Audit\Discovery\Inspectors\InfrastructureInspector;
use App\Audit\Discovery\Inspectors\LaravelInspector;
use App\Audit\Discovery\Inspectors\TestingInspector;
use App\Audit\Discovery\Manifests\ComposerManifest;
use App\Audit\Discovery\Manifests\NpmManifest;
use App\Audit\Discovery\Profile\ProfileBuilder;

/**
 * Entry point of the Project Discovery Core: inspects a directory and
 * produces a normalized {@see DiscoveryResult} describing its detected
 * stack, without ever executing anything that originates in that
 * directory (see docs/auditing/project-discovery.md for the full security
 * model). Has no dependency on the web UI, MCP, or a database — it's pure
 * PHP over the filesystem, safe to call from a CLI command, a queued job,
 * or a future HTTP endpoint alike.
 */
final readonly class ProjectDiscovery
{
    public function __construct(
        private ComposerInspector $composer = new ComposerInspector,
        private LaravelInspector $laravel = new LaravelInspector,
        private FrontendInspector $frontend = new FrontendInspector,
        private TestingInspector $testing = new TestingInspector,
        private InfrastructureInspector $infrastructure = new InfrastructureInspector,
        private DatabaseInspector $database = new DatabaseInspector,
        private ProfileBuilder $profileBuilder = new ProfileBuilder,
    ) {}

    public function discover(string $path): DiscoveryResult
    {
        $real = realpath($path);

        if ($real === false) {
            return DiscoveryResult::failed($path, DiscoveryStatus::PathNotFound);
        }

        if (! is_dir($real)) {
            return DiscoveryResult::failed($real, DiscoveryStatus::PathNotDirectory);
        }

        if (! is_readable($real)) {
            return DiscoveryResult::failed($real, DiscoveryStatus::PathNotReadable);
        }

        $fs = new ProjectFilesystem($real);

        $composerManifest = ComposerManifest::read($fs);
        $npmManifest = NpmManifest::read($fs);

        $profile = $this->profileBuilder->build(
            path: $real,
            fs: $fs,
            composerManifest: $composerManifest,
            npmManifest: $npmManifest,
            composer: $this->composer->inspect($composerManifest),
            laravel: $this->laravel->inspect($fs, $composerManifest),
            frontend: $this->frontend->inspect($fs, $npmManifest),
            testing: $this->testing->inspect($fs, $composerManifest, $npmManifest),
            infrastructure: $this->infrastructure->inspect($fs),
            database: $this->database->inspect($fs),
        );

        return DiscoveryResult::ok($real, $profile);
    }
}
