<?php

namespace App\Mcp\Support;

use App\Audit\Findings\Redaction\OutputSanitizer;

/**
 * The door through which target-derived text (titles, messages, snippets,
 * paths) leaves LaraDogs through MCP (Phase 11).
 *
 * Since Phase 12 the implementation lives in {@see OutputSanitizer} — ONE
 * sanitized-evidence path shared by MCP, the Dashboard and the CLI (the
 * remediation plan is built with it). This class keeps the MCP-facing
 * constants and API unchanged.
 */
final class McpSanitizer
{
    public const int MAX_TITLE = OutputSanitizer::MAX_TITLE;

    public const int MAX_MESSAGE = OutputSanitizer::MAX_MESSAGE;

    public const int MAX_SNIPPET = OutputSanitizer::MAX_SNIPPET;

    public const int MAX_PATH = OutputSanitizer::MAX_PATH;

    public function __construct(private OutputSanitizer $sanitizer = new OutputSanitizer) {}

    /**
     * Redacts, path-masks, control-strips and bounds a target-derived string.
     */
    public function text(?string $text, int $max, ?string $projectRoot = null): ?string
    {
        return $this->sanitizer->text($text, $max, $projectRoot);
    }

    /**
     * A project-relative path, or null when it cannot be expressed safely
     * (absolute, traversal, drive letter, control characters, too long).
     */
    public function path(?string $path, ?string $projectRoot = null): ?string
    {
        return $this->sanitizer->path($path, $projectRoot);
    }
}
