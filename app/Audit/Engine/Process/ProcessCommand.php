<?php

namespace App\Audit\Engine\Process;

/**
 * The input to a future {@see ProcessRunner}. Deliberately argv-only —
 * there is no shell-string field on this type, so nothing that accepts a
 * ProcessCommand can be tricked into interpolating untrusted input into a
 * shell; that class of bug is structurally unavailable, not just avoided
 * by convention.
 */
final readonly class ProcessCommand
{
    /**
     * @param  list<string>  $argv  Program + arguments, e.g.
     *                              `['composer', 'audit', '--format=json']`.
     * @param  array<string,string>  $environment  Explicit, allowlisted
     *                                             environment variables
     *                                             only — never the full
     *                                             parent process
     *                                             environment.
     */
    public function __construct(
        public array $argv,
        public string $workingDirectory,
        public array $environment = [],
        public int $timeoutSeconds = 30,
    ) {}
}
