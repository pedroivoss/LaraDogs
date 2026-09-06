<?php

/**
 * Writes far more stdout than any reasonable cap — used to prove a
 * ProcessRunner enforces its output-size limit and reports truncation
 * rather than buffering unboundedly. Bytes given as argv[1] (default
 * 10MB), written in chunks so a streaming implementation is actually
 * exercised rather than trivially satisfied by one huge write.
 */
$totalBytes = isset($argv[1]) ? (int) $argv[1] : 10_000_000;
$chunk = str_repeat('a', 65_536);

$written = 0;

while ($written < $totalBytes) {
    fwrite(STDOUT, $chunk);
    $written += strlen($chunk);
}
