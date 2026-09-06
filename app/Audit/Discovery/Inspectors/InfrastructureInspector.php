<?php

namespace App\Audit\Discovery\Inspectors;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Profile\InfrastructureProfile;
use App\Audit\Discovery\Support\Detection;

/**
 * Detects containerization/CI/queue-adjacent signals from file presence
 * and plain-text pattern matching. `routes/console.php` and `.env.example`
 * are read as raw text and scanned with regular expressions only — never
 * `include`d, `require`d, or evaluated as PHP.
 */
final class InfrastructureInspector
{
    public function inspect(ProjectFilesystem $fs): InfrastructureProfile
    {
        return new InfrastructureProfile(
            docker: $this->fileDetection($fs, ['Dockerfile']),
            dockerCompose: $this->fileDetection($fs, ['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml']),
            githubActions: $this->anyMatchDetection($fs, ['.github/workflows/*.yml', '.github/workflows/*.yaml']),
            gitlabCi: $this->fileDetection($fs, ['.gitlab-ci.yml']),
            redisHints: $this->textHintDetection($fs, ['.env.example'], '/^\s*REDIS_/mi'),
            queueHints: $this->queueHints($fs),
            schedulerHints: $this->textHintDetection($fs, ['routes/console.php'], '/->schedule\s*\(|Schedule::/'),
        );
    }

    /**
     * @param  list<string>  $files
     */
    private function fileDetection(ProjectFilesystem $fs, array $files): Detection
    {
        foreach ($files as $file) {
            if ($fs->fileExists($file)) {
                return Detection::detected($file);
            }
        }

        return Detection::notDetected();
    }

    /**
     * @param  list<string>  $globs
     */
    private function anyMatchDetection(ProjectFilesystem $fs, array $globs): Detection
    {
        $match = $fs->matchesAny($globs);

        return $match !== null ? Detection::detected($match) : Detection::notDetected();
    }

    /**
     * @param  list<string>  $files
     */
    private function textHintDetection(ProjectFilesystem $fs, array $files, string $pattern): Detection
    {
        foreach ($files as $file) {
            $contents = $fs->readFile($file);

            if ($contents !== null && preg_match($pattern, $contents) === 1) {
                return Detection::detected($file);
            }
        }

        return Detection::notDetected();
    }

    private function queueHints(ProjectFilesystem $fs): Detection
    {
        $contents = $fs->readFile('.env.example');

        if ($contents !== null && preg_match('/^\s*QUEUE_CONNECTION\s*=\s*(?!sync\b|database\b\s*$)\S+/mi', $contents) === 1) {
            return Detection::detected('.env.example: QUEUE_CONNECTION');
        }

        return Detection::notDetected();
    }
}
