<?php

namespace App\Mcp\Support;

use App\Audit\Findings\Redaction\EvidenceRedactor;

/**
 * The ONLY door through which target-derived text (titles, messages,
 * snippets, paths) leaves LaraDogs through MCP (Phase 11).
 *
 * Persisted evidence was already passed through {@see EvidenceRedactor} at
 * ingestion, but that redactor is a deliberately minimal defense-in-depth
 * pass (it only knows AWS key ids and `NAME_SECRET=value` shapes) and older
 * rows may pre-date it — MCP therefore never trusts persistence: it re-applies
 * that redactor AND a broader set of secret shapes, masks absolute host
 * paths, and bounds every string. It is still best-effort, not a secret
 * scanner: source text is UNTRUSTED DATA that may contain anything.
 */
final class McpSanitizer
{
    public const int MAX_TITLE = 300;

    public const int MAX_MESSAGE = 2000;

    public const int MAX_SNIPPET = 1500;

    public const int MAX_PATH = 500;

    private const array SECRET_PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/' => '[REDACTED PRIVATE KEY]',
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]{16,}/i' => 'Bearer [REDACTED]',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/' => '[REDACTED TOKEN]',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/' => '[REDACTED TOKEN]',
        '/\bsk-[A-Za-z0-9_-]{20,}/' => '[REDACTED TOKEN]',
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}/' => '[REDACTED TOKEN]',
        '/\bAIza[0-9A-Za-z_-]{30,}/' => '[REDACTED TOKEN]',
        '/\bldmcp_[0-9a-f]{4,}(?:_[0-9a-f]+)?/' => '[REDACTED TOKEN]',
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/' => '[REDACTED JWT]',
    ];

    /** Absolute host/container locations that must never be echoed. */
    private const string ABSOLUTE_PATH = '#(?<![A-Za-z0-9_.\-])(?:/(?:Users|home|projects|private|var|root|tmp|opt|mnt|srv|app|etc)/[^\s:\'"`)<>]+|[A-Za-z]:\\\\[^\s:\'"`)<>]+)#';

    public function __construct(private EvidenceRedactor $redactor = new EvidenceRedactor) {}

    public function text(?string $text, int $max, ?string $projectRoot = null): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = $this->redactor->redact($text) ?? '';

        foreach (self::SECRET_PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        if ($projectRoot !== null && $projectRoot !== '') {
            $text = str_replace(rtrim($projectRoot, '/').'/', '', $text);
        }

        $text = preg_replace(self::ABSOLUTE_PATH, '[path]', $text) ?? $text;
        // Control characters (except tab/newline) have no business in evidence.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_scrub($text, 'UTF-8')) ?? $text;

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * A project-RELATIVE path or null: never absolute, never a traversal.
     */
    public function path(?string $path, ?string $projectRoot = null): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);

        if ($projectRoot !== null && $projectRoot !== '') {
            $root = rtrim(str_replace('\\', '/', $projectRoot), '/').'/';

            if (str_starts_with($path, $root)) {
                $path = substr($path, strlen($root));
            }
        }

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1
            || in_array('..', explode('/', $path), true) || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        return mb_strlen($path) > self::MAX_PATH ? null : $path;
    }
}
