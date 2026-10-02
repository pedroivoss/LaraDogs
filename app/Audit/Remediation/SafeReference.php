<?php

namespace App\Audit\Remediation;

/**
 * Reference-URL hygiene for the remediation plan. References come from rule
 * metadata and upstream advisories (untrusted data): only plain `https` URLs
 * to a named host survive. Never fetched, never followed by LaraDogs.
 */
final class SafeReference
{
    public const int MAX_LENGTH = 500;

    public static function normalize(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F\\\\<>"\'`]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        // No credentials in the URL, and only a syntactically plausible host.
        if (isset($parts['user']) || isset($parts['pass']) || preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $parts['host']) !== 1) {
            return null;
        }

        return $url;
    }

    /**
     * @param  iterable<mixed>  $urls
     * @return list<string>
     */
    public static function normalizeAll(iterable $urls, int $max): array
    {
        $safe = [];

        foreach ($urls as $url) {
            $normalized = self::normalize($url);

            if ($normalized !== null && ! in_array($normalized, $safe, true)) {
                $safe[] = $normalized;
            }

            if (count($safe) >= $max) {
                break;
            }
        }

        return $safe;
    }
}
