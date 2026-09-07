<?php

namespace App\Audit\Analyzers\Npm;

/**
 * A minimal, read-only inspector for a target's project-level `.npmrc` —
 * NOT a general npmrc parser (see docs/auditing/analyzers/npm-audit.md
 * for why most of npmrc's trust-relevant surface doesn't need one:
 * `registry`/`@scope:registry`/`audit`/`userconfig`/`globalconfig`/
 * `cache`/`proxy`/`https-proxy` are all already neutralized by
 * {@see NpmAuditAnalyzer}'s explicit CLI flags and forced environment
 * variables, verified empirically — see that class's own docblock).
 *
 * This exists for the one category those flags do NOT neutralize:
 * `cafile`/`cert`/`certfile`/`key`/`keyfile` let a project's own
 * `.npmrc` make npm read an arbitrary file from disk into TLS trust
 * material (verified against the real npm CLI source —
 * `Definition('cafile').flatten()` calls `maybeReadFile(obj.cafile)`
 * directly). {@see NpmAuditAnalyzer} fails closed whenever this
 * inspector reports any of these present, rather than attempting to
 * neutralize them the same way as the other keys.
 *
 * Deliberately: static, read-only (never writes to or deletes anything);
 * bounded (a `.npmrc` larger than {@see self::MAX_BYTES} is treated as
 * unsafe rather than partially scanned); line-based, not a real INI
 * parser (no `${VARIABLE}` interpolation, no following of any path a
 * value happens to contain); never returns or logs a matched line's
 * VALUE, only which known-dangerous key names were present — an auth
 * token or file path in the target's own `.npmrc` is never captured,
 * even for diagnostics.
 */
final class NpmConfigInspector
{
    public const int MAX_BYTES = 65_536;

    /**
     * Keys that grant npm an arbitrary-file-read primitive when set in a
     * project's own `.npmrc` (`cafile`) or otherwise supply TLS trust
     * material this analyzer has no way to verify is safe
     * (`cert`/`certfile`/`key`/`keyfile`) — never neutralized by a CLI
     * flag the way `registry`/`proxy`/etc. are, so their mere presence
     * is treated as unsafe. Matched both in plain form (`cafile=...`)
     * and registry-scoped form (`//host/:keyfile=...`).
     */
    private const array DANGEROUS_KEYS = ['cafile', 'cert', 'certfile', 'key', 'keyfile'];

    /**
     * @return list<string> the dangerous key names found (never their
     *                      values); empty when the project has no
     *                      `.npmrc`, or it exists but declares none of
     *                      {@see self::DANGEROUS_KEYS}
     *
     * @throws NpmConfigTooLargeException when a `.npmrc` exists but
     *                                    exceeds {@see self::MAX_BYTES}
     *                                    — the caller must treat this as
     *                                    "cannot be safely inspected",
     *                                    not "no dangerous keys found"
     */
    public function inspect(string $projectPath): array
    {
        $path = rtrim($projectPath, '/').'/.npmrc';

        if (! is_file($path)) {
            return [];
        }

        $size = @filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            throw new NpmConfigTooLargeException($path);
        }

        $contents = @file_get_contents($path, length: self::MAX_BYTES);

        if ($contents === false) {
            // Unreadable is treated the same as "nothing dangerous found"
            // here — the file simply cannot be loaded at all, so it also
            // cannot be loaded by the npm subprocess (running as the same
            // user) to do anything harmful either.
            return [];
        }

        $found = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            $equalsPosition = strpos($line, '=');

            if ($equalsPosition === false) {
                continue;
            }

            $key = $this->normalizeKey(substr($line, 0, $equalsPosition));

            if (in_array($key, self::DANGEROUS_KEYS, true) && ! in_array($key, $found, true)) {
                $found[] = $key;
            }
        }

        return $found;
    }

    /**
     * Strips a registry-scoped prefix (`//host/:key` or `@scope:key`) and
     * lowercases, so `//other-registry.tld/:keyfile` and `KEYFILE` both
     * normalize to `keyfile`.
     */
    private function normalizeKey(string $rawKey): string
    {
        $key = trim($rawKey);

        if (str_starts_with($key, '//')) {
            $colonPosition = strrpos($key, ':');
            $key = $colonPosition !== false ? substr($key, $colonPosition + 1) : $key;
        } elseif (str_starts_with($key, '@')) {
            $colonPosition = strpos($key, ':');
            $key = $colonPosition !== false ? substr($key, $colonPosition + 1) : $key;
        }

        return strtolower(trim($key, "\"' "));
    }
}
