<?php

/**
 * Sleeps for the number of seconds given as argv[1] — used to prove a
 * ProcessRunner enforces a real timeout rather than waiting indefinitely.
 */
$seconds = isset($argv[1]) ? (float) $argv[1] : 1.0;

usleep((int) ($seconds * 1_000_000));

fwrite(STDOUT, 'done'.PHP_EOL);
