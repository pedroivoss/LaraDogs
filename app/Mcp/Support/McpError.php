<?php

namespace App\Mcp\Support;

use RuntimeException;

/**
 * A typed, client-safe tool failure. The message is written by LaraDogs
 * (never derived from target data, a SQL error, a path or a stack trace),
 * and `details` carries only bounded public identifiers.
 */
final class McpError extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $details
     */
    public function __construct(
        public readonly McpErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{error: array<string,mixed>}
     */
    public function toEnvelope(): array
    {
        return ['error' => array_filter([
            'code' => $this->errorCode->value,
            'message' => $this->getMessage(),
            'details' => $this->details === [] ? null : $this->details,
        ], fn ($v) => $v !== null)];
    }
}
