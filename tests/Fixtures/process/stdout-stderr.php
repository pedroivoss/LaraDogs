<?php

/**
 * Writes a known, distinguishable line to both stdout and stderr — used to
 * prove a ProcessRunner captures each stream separately rather than
 * merging or dropping one of them.
 */
fwrite(STDOUT, 'stdout-line'.PHP_EOL);
fwrite(STDERR, 'stderr-line'.PHP_EOL);
