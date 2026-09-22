<?php

namespace Tests\Support\Process;

use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Process\ProcessRunner;

/**
 * Delegates to a real runner while recording every command it was given, so
 * a test can assert WHICH commands, arguments and environment were used.
 */
final class RecordingProcessRunner implements ProcessRunner
{
    /** @var list<ProcessCommand> */
    public array $commands = [];

    public function __construct(private readonly ProcessRunner $inner) {}

    public function run(ProcessCommand $command): ProcessResult
    {
        $this->commands[] = $command;

        return $this->inner->run($command);
    }

    /**
     * The Git subcommand of every recorded call (the first argv element
     * after the global options).
     *
     * @return list<string>
     */
    public function subcommands(): array
    {
        return array_map(function (ProcessCommand $command): string {
            $args = array_slice($command->argv, 1);

            for ($i = 0; $i < count($args); $i++) {
                if ($args[$i] === '-c') {
                    $i++;

                    continue;
                }

                if (str_starts_with($args[$i], '--')) {
                    continue;
                }

                return $args[$i];
            }

            return '';
        }, $this->commands);
    }
}
