<?php

namespace App\Console\Commands;

use App\Audit\Discovery\DiscoveryResult;
use App\Audit\Discovery\DiscoveryStatus;
use App\Audit\Discovery\Profile\PackageManager;
use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Discovery\Support\Detection;
use App\Audit\Discovery\Support\VersionDetection;
use Illuminate\Console\Command;

/**
 * Thin CLI adapter over the Project Discovery Core. Contains no detection
 * logic of its own — every signal below comes straight from the
 * {@see ProjectDiscovery} result.
 */
final class InspectProjectCommand extends Command
{
    protected $signature = 'laradogs:inspect {path : Path to the project to inspect} {--json : Output the full profile as JSON}';

    protected $description = 'Inspect a directory and report its detected stack (read-only, never executes target code)';

    public function handle(ProjectDiscovery $discovery): int
    {
        $path = (string) $this->argument('path');

        $result = $discovery->discover($path);

        if ($result->profile === null) {
            $this->error($this->describeFailure($result));

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($result->profile);

        return self::SUCCESS;
    }

    private function describeFailure(DiscoveryResult $result): string
    {
        return match ($result->status) {
            DiscoveryStatus::PathNotFound => "Path not found: {$result->path}",
            DiscoveryStatus::PathNotDirectory => "Not a directory: {$result->path}",
            DiscoveryStatus::PathNotReadable => "Path is not readable: {$result->path}",
            DiscoveryStatus::Ok => 'Unexpected: reported as failure but status is ok.',
        };
    }

    private function renderHuman(ProjectProfile $profile): void
    {
        $this->components->info("Project: {$profile->path}");
        $this->line("Type: {$profile->type->value}");

        $this->newLine();
        $this->line('<fg=yellow>Backend</>');
        $this->version('PHP', $profile->backend->php);
        $this->detection('Composer', $profile->backend->composer);
        $this->detection('Composer lock', $profile->backend->composerLock);
        $this->version('Laravel', $profile->backend->laravel);
        $this->detection('Blade', $profile->backend->blade);
        $this->detection('Livewire', $profile->backend->livewire);
        $this->detection('Inertia (backend)', $profile->backend->inertia);
        foreach ($profile->backend->packages as $name => $detection) {
            $this->detection('  '.ucfirst($name), $detection);
        }

        $this->newLine();
        $this->line('<fg=yellow>Frontend</>');
        $this->detection('Node/npm', $profile->frontend->node);
        $packageManager = $profile->frontend->packageManager;
        $this->line('  Package manager: '.($packageManager instanceof PackageManager ? $packageManager->value : 'unknown'));
        $this->detection('Vite', $profile->frontend->vite);
        $this->detection('React', $profile->frontend->react);
        $this->detection('Vue', $profile->frontend->vue);
        $this->detection('TypeScript', $profile->frontend->typescript);
        $this->detection('Tailwind', $profile->frontend->tailwind);
        $this->detection('Inertia (client)', $profile->frontend->inertiaClient);

        $this->newLine();
        $this->line('<fg=yellow>Testing</>');
        $this->detection('Pest', $profile->testing->pest);
        $this->detection('PHPUnit', $profile->testing->phpunit);
        $this->detection('Playwright', $profile->testing->playwright);
        $this->detection('Vitest', $profile->testing->vitest);
        $this->detection('Jest', $profile->testing->jest);
        $this->detection('Cypress', $profile->testing->cypress);

        $this->newLine();
        $this->line('<fg=yellow>Infrastructure</>');
        $this->detection('Docker', $profile->infrastructure->docker);
        $this->detection('Docker Compose', $profile->infrastructure->dockerCompose);
        $this->detection('GitHub Actions', $profile->infrastructure->githubActions);
        $this->detection('GitLab CI', $profile->infrastructure->gitlabCi);
        $this->detection('Redis (hint)', $profile->infrastructure->redisHints);
        $this->detection('Queue (hint)', $profile->infrastructure->queueHints);
        $this->detection('Scheduler (hint)', $profile->infrastructure->schedulerHints);

        $this->newLine();
        $this->line('<fg=yellow>Database</>');
        $detected = $profile->database->detectedDrivers();
        $this->line($detected === [] ? '  No driver detected from static evidence.' : '  Detected: '.implode(', ', $detected));

        if ($profile->issues !== []) {
            $this->newLine();
            $this->components->warn('Issues');
            foreach ($profile->issues as $issue) {
                $this->line("  - {$issue->source}: {$issue->message}");
            }
        }
    }

    private function detection(string $label, Detection $detection): void
    {
        $suffix = $detection->evidence !== null ? " ({$detection->evidence})" : '';

        $this->line("  {$label}: {$detection->status->value}{$suffix}");
    }

    private function version(string $label, VersionDetection $detection): void
    {
        $suffix = match (true) {
            $detection->installedVersion !== null => " (installed: {$detection->installedVersion})",
            $detection->constraint !== null => " (constraint: {$detection->constraint})",
            default => '',
        };

        $this->line("  {$label}: {$detection->status->value}{$suffix}");
    }
}
