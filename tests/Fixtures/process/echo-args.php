<?php

/**
 * Prints every argv entry (excluding this script's own path) on its own
 * line — used to prove a ProcessRunner passes argv through verbatim and
 * never through a shell (a literal `;`/`&&`/backtick argument must show up
 * as a single, inert argument line, never be interpreted).
 */
foreach (array_slice($argv, 1) as $arg) {
    fwrite(STDOUT, $arg.PHP_EOL);
}
