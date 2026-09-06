<?php

/**
 * Exits with the code given as argv[1] (default 0) — used to prove a
 * ProcessRunner reports the real process exit code.
 */
$code = isset($argv[1]) ? (int) $argv[1] : 0;

exit($code);
