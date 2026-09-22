<?php

namespace App\Audit\Source\Git;

/**
 * Turns a raw, UNTRUSTED `remote.origin.url` into a string that is safe to
 * persist and render: never credentials, never a host filesystem path.
 *
 * - `https://user:token@host/org/repo.git` -> `https://host/org/repo.git`
 *   (userinfo, query string and fragment are dropped);
 * - `git@host:org/repo.git` -> `host:org/repo.git` (SCP-style, user dropped);
 * - `ssh://git@host:2222/org/repo.git` -> `ssh://host:2222/org/repo.git`;
 * - `file://...`, absolute/relative/home/Windows paths -> {@see LOCAL_LABEL};
 * - anything else (empty, unknown scheme, suspicious characters) ->
 *   {@see UNKNOWN_LABEL}, never echoed back.
 *
 * Generic Git only — no host (GitHub, GitLab, ...) is assumed or special-cased.
 */
final class RemoteUrlSanitizer
{
    public const string LOCAL_LABEL = 'Local remote';

    public const string UNKNOWN_LABEL = 'Unrecognized remote';

    private const int MAX_INPUT_LENGTH = 2048;

    private const int MAX_OUTPUT_LENGTH = 255;

    private const array NETWORK_SCHEMES = ['http', 'https', 'ssh', 'git', 'git+ssh', 'ssh+git'];

    public static function sanitize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (strlen($raw) > self::MAX_INPUT_LENGTH || preg_match('/[\x00-\x1F\x7F\s]/', $raw) === 1) {
            return self::UNKNOWN_LABEL;
        }

        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*)://(.*)$#s', $raw, $m) === 1) {
            return self::fromUrl(strtolower($m[1]), $m[2]);
        }

        if (self::looksLocal($raw)) {
            return self::LOCAL_LABEL;
        }

        return self::fromScpLike($raw);
    }

    private static function fromUrl(string $scheme, string $rest): string
    {
        if ($scheme === 'file') {
            return self::LOCAL_LABEL;
        }

        if (! in_array($scheme, self::NETWORK_SCHEMES, true)) {
            return self::UNKNOWN_LABEL;
        }

        $split = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $split);
        $remainder = substr($rest, $split);

        // Everything before the LAST '@' of the authority is userinfo
        // (user[:password]) — dropped, even when the password itself
        // contains an unescaped '@'.
        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);

        if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)(?::(\d{1,5}))?$/', $hostPort, $h) !== 1) {
            return self::UNKNOWN_LABEL;
        }

        $path = $remainder;
        $cut = strcspn($path, '?#');
        $path = substr($path, 0, $cut);

        // A '@' left in the path means the authority was ambiguous (e.g. a
        // credential containing '/'), so nothing here can be trusted.
        if (str_contains($path, '@') || preg_match('#^[A-Za-z0-9._~/%+:-]*$#', $path) !== 1) {
            return self::UNKNOWN_LABEL;
        }

        $out = $scheme.'://'.$h[1].(isset($h[2]) ? ':'.$h[2] : '').$path;

        return self::bound($out);
    }

    private static function fromScpLike(string $raw): string
    {
        // [user@]host:path — but never `host://...` (handled above) nor a
        // single-letter "host" (a Windows drive, handled as local).
        if (preg_match('/^(?:[^@\/:]*@)?(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9][A-Za-z0-9.-]*):(.+)$/s', $raw, $m) !== 1) {
            return self::UNKNOWN_LABEL;
        }

        $path = $m[2];

        if (str_contains($path, '@') || preg_match('#^[A-Za-z0-9._~/%+:-]+$#', $path) !== 1) {
            return self::UNKNOWN_LABEL;
        }

        return self::bound($m[1].':'.$path);
    }

    private static function looksLocal(string $raw): bool
    {
        return str_starts_with($raw, '/')
            || str_starts_with($raw, './')
            || str_starts_with($raw, '../')
            || str_starts_with($raw, '~')
            || str_starts_with($raw, '\\')
            || $raw === '.'
            || $raw === '..'
            || preg_match('#^[A-Za-z]:[\\\\/]#', $raw) === 1
            || (! str_contains($raw, ':') && ! str_contains($raw, '@'));
    }

    private static function bound(string $value): string
    {
        return strlen($value) > self::MAX_OUTPUT_LENGTH ? self::UNKNOWN_LABEL : $value;
    }
}
