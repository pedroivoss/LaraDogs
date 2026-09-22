<?php

namespace App\Console\Commands;

use App\Audit\Discovery\Profile\PackageManager;
use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Discovery\Support\Detection;
use App\Audit\Discovery\Support\VersionDetection;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Audit\Source\Git\GitRepositoryState;
use App\Audit\Source\Git\GitSnapshot;
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

    public function handle(ProjectDiscovery $discovery, GitRepositoryInspector $git): int
    {
        $path = (string) $this->argument('path');

        $result = $discovery->discover($path);

        if ($result->profile === null) {
            $this->error($result->status->describe($result->path));

            return self::FAILURE;
        }

        // Local, read-only Git metadata (Phase 9) — the one canonical
        // inspector, never a network call.
        $snapshot = $git->inspect($result->path);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                [...$result->jsonSerialize(), 'git' => $this->gitToArray($snapshot)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->renderHuman($result->profile);
        $this->renderGit($snapshot);

        return self::SUCCESS;
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

    /**
     * @return array<string,mixed>
     */
    private function gitToArray(GitSnapshot $snapshot): array
    {
        if (! $snapshot->isRepository()) {
            return ['type' => $snapshot->state->value];
        }

        return [
            'type' => 'git',
            'commit' => $snapshot->commitSha,
            'branch' => $snapshot->branch,
            'detached' => $snapshot->detached,
            'dirty' => $snapshot->dirty,
            'commit_at' => $snapshot->commitTimestamp?->format(DATE_ATOM),
            'commit_subject' => $snapshot->commitSubject,
            'remote' => $snapshot->remoteOrigin,
        ];
    }

    private function renderGit(GitSnapshot $snapshot): void
    {
        $this->newLine();
        $this->line('<fg=yellow>Git</>');

        match ($snapshot->state) {
            GitRepositoryState::NotRepository => $this->line('  Git: not detected'),
            GitRepositoryState::Bare => $this->line('  Git: bare repository (no working tree — cannot be audited as source)'),
            GitRepositoryState::Unavailable => $this->line('  Git: unavailable ('.($snapshot->unavailableReason ?? 'unknown').')'),
            GitRepositoryState::Repository => $this->renderRepository($snapshot),
        };
    }

    private function renderRepository(GitSnapshot $snapshot): void
    {
        $this->line('  Git: detected');
        $this->line('  Branch: '.($snapshot->detached ? 'detached HEAD' : ($snapshot->branch ?? 'unknown')));
        $this->line('  Revision: '.($snapshot->shortSha() ?? 'no commits yet'));
        $this->line('  Working tree: '.($snapshot->dirty === null ? 'unknown' : ($snapshot->dirty ? 'dirty' : 'clean')));

        if ($snapshot->remoteOrigin !== null) {
            $this->line("  Origin: {$snapshot->remoteOrigin}");
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
