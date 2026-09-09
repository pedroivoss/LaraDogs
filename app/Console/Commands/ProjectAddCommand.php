<?php

namespace App\Console\Commands;

use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RegisterProjectOutcome;
use App\Models\Audit\Project;
use Illuminate\Console\Command;

/**
 * Thin CLI adapter over {@see RegisterProject}. Registering the same
 * resolved path twice is idempotent (exit 0, "already registered"), never
 * a duplicate — see that class's own docblock for the full semantics.
 */
final class ProjectAddCommand extends Command
{
    protected $signature = 'laradogs:project:add
        {path : Path to the project to register}
        {--name= : Display name (defaults to the directory basename)}
        {--json : Output the result as JSON}';

    protected $description = 'Register a local directory as a Project LaraDogs can repeatedly audit';

    public function handle(RegisterProject $register): int
    {
        $path = (string) $this->argument('path');
        $name = $this->option('name');

        $result = $register->register($path, $name !== null ? (string) $name : null);

        if (! $result->succeeded()) {
            $failure = $result->discoveryFailure;
            $message = $failure === null
                ? 'Unknown registration failure.'
                : $failure->status->describe($failure->path);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(
                    ['succeeded' => false, 'error' => $message],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $project = $result->project;

        if ($project === null) {
            $this->error('Unexpected: registration succeeded but no project was returned.');

            return self::FAILURE;
        }

        $created = $result->outcome === RegisterProjectOutcome::Created;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                [
                    'succeeded' => true,
                    'created' => $created,
                    'project' => $this->projectToArray($project),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($created) {
            $this->components->info("Registered project: {$project->name} ({$project->public_id})");
        } else {
            $this->components->info("Already registered: {$project->name} ({$project->public_id})");
        }

        $this->line("  Path: {$project->path}");

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function projectToArray(Project $project): array
    {
        return [
            'id' => $project->public_id,
            'name' => $project->name,
            'path' => $project->path,
        ];
    }
}
