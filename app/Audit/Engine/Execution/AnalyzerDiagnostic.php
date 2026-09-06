<?php

namespace App\Audit\Engine\Execution;

use JsonSerializable;

/**
 * A problem with the analyzer's own execution (binary missing, malformed
 * output, an internal error) — never a vulnerability or issue found IN the
 * analyzed project. That's a `Finding` (Phase 3), a different concept
 * entirely; see docs/auditing/audit-engine.md's "Diagnostics vs Findings"
 * section.
 */
final readonly class AnalyzerDiagnostic implements JsonSerializable
{
    public function __construct(
        public DiagnosticLevel $level,
        public string $message,
        public ?string $code = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'level' => $this->level->value,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
