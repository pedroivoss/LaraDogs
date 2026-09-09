<?php

namespace App\Console\Commands;

use App\Audit\Projects\Query\ProjectListQuery;
use App\Models\Audit\Project;
use Illuminate\Console\Command;

/**
 * Thin CLI adapter over {@see ProjectListQuery} — no query logic of its
 * own.
 */
final class ProjectListCommand extends Command
{
    protected $signature = 'laradogs:project:list {--json : Output the result as JSON}';

    protected $description = 'List every Project registered with LaraDogs';

    public function handle(ProjectListQuery $query): int
    {
        $projects = $query->all();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                ['projects' => $projects->map($this->projectToArray(...))->all()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($projects->isEmpty()) {
            $this->components->info('No projects registered yet. Use `laradogs:project:add {path}` to register one.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Path', 'Last Scan', 'Open Findings'],
            $projects->map(fn (Project $project): array => [
                $project->public_id,
                $project->name,
                $project->path,
                $project->latestScan === null
                    ? '(never scanned)'
                    : sprintf('%s (%s)', $project->latestScan->started_at->diffForHumans(), $project->latestScan->status->value),
                (string) $project->open_findings_count,
            ])->all(),
        );

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
            'last_scan' => $project->latestScan === null ? null : [
                'id' => $project->latestScan->public_id,
                'status' => $project->latestScan->status->value,
                'started_at' => $project->latestScan->started_at->toIso8601String(),
                'finished_at' => $project->latestScan->finished_at?->toIso8601String(),
            ],
            'open_findings_count' => $project->open_findings_count,
        ];
    }
}
