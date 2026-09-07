<?php

namespace App\Audit\Analyzers\Npm;

use RuntimeException;

/**
 * Thrown by {@see NpmConfigInspector::inspect()} when a target's `.npmrc`
 * exceeds the inspector's size cap — this is a "cannot safely inspect,"
 * not a "found nothing" outcome; {@see NpmAuditAnalyzer} treats it as
 * unsafe and fails closed, the same as detecting a dangerous key.
 */
final class NpmConfigTooLargeException extends RuntimeException
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf(
            'Target .npmrc at "%s" exceeds %d bytes and cannot be safely inspected.',
            $path,
            NpmConfigInspector::MAX_BYTES,
        ));
    }
}
