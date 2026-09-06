<?php

namespace App\Audit\Findings\Redaction;

/**
 * A minimal, conservative redactor applied to evidence text
 * (code snippets, context code, string metadata values) before it's
 * persisted, so an obvious secret an analyzer happened to capture as
 * evidence isn't stored in full by accident.
 *
 * This is **defense-in-depth, not a secret scanner.** It only recognizes a
 * couple of very low-false-positive patterns (an AWS-style access key id,
 * and an obvious `SOMETHING_SECRET=value`-shaped assignment). It does not
 * attempt entropy analysis, a comprehensive provider-specific pattern
 * list, or anything that would risk false positives mangling ordinary
 * code — that's a real secret-scanning feature for a future phase, not
 * this one.
 */
final class EvidenceRedactor
{
    /**
     * @var list<string>
     */
    private const array SENSITIVE_KEY_HINTS = [
        'SECRET', 'PASSWORD', 'PASSWD', 'TOKEN', 'API_KEY', 'APIKEY', 'PRIVATE_KEY', 'ACCESS_KEY',
    ];

    public function redact(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $text = $this->redactAwsAccessKeyIds($text);

        return $this->redactKeyValueSecrets($text);
    }

    /**
     * Shallow, one-level redaction over an associative array's string
     * values (e.g. a candidate's `metadata`) — nested arrays/objects are
     * left as-is, since this is a best-effort pass, not exhaustive
     * traversal.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function redactAwsAccessKeyIds(string $text): string
    {
        return preg_replace_callback(
            '/\b((?:AKIA|ASIA)[0-9A-Z]{16})\b/',
            fn (array $matches): string => $this->partiallyMask($matches[1]),
            $text,
        ) ?? $text;
    }

    private function redactKeyValueSecrets(string $text): string
    {
        $hints = implode('|', self::SENSITIVE_KEY_HINTS);

        return preg_replace_callback(
            '/\b([A-Z0-9_]*(?:'.$hints.')[A-Z0-9_]*)(\s*[:=]\s*)("?)([^\s"\']+)("?)/i',
            fn (array $matches): string => $matches[1].$matches[2].$matches[3].$this->partiallyMask($matches[4]).$matches[5],
            $text,
        ) ?? $text;
    }

    private function partiallyMask(string $value): string
    {
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4).str_repeat('*', $length - 8).substr($value, -4);
    }
}
