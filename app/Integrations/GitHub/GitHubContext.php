<?php

namespace App\Integrations\GitHub;

/**
 * Validated GitHub Actions/repository context — the ONLY place LaraDogs
 * reads `GITHUB_*` environment variables. Every field is either a
 * strictly-validated value or `null`; nothing here is ever used to build a
 * shell command (LaraDogs has no shell execution at all — see
 * ADR-0011/docs/development/process-execution.md), and every URL is
 * validated (`https://`, no embedded userinfo, no control characters)
 * before it is used as an HTTP client base URI.
 *
 * `GITHUB_API_URL`/`GITHUB_SERVER_URL` are read directly from the
 * environment (falling back to `config('laradogs.github')`) because GitHub
 * Actions itself sets them correctly on GitHub Enterprise Server — reading
 * them is how GHES support falls out for free, without a per-instance
 * LaraDogs config change.
 */
final readonly class GitHubContext
{
    private const string REPOSITORY_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9._-]{1,100}$/';

    private const string SHA_PATTERN = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i';

    public function __construct(
        public bool $actionsMode,
        public ?string $repositoryOwner,
        public ?string $repositoryName,
        public ?string $sha,
        public string $serverUrl,
        public string $apiUrl,
    ) {}

    /**
     * @param  array<string,string|false>|null  $env  an explicit environment map for tests; real `getenv()` otherwise
     */
    public static function fromEnvironment(?array $env = null, string $configApiUrl = 'https://api.github.com', string $configServerUrl = 'https://github.com'): self
    {
        $read = fn (string $key): ?string => self::clean($env !== null ? ($env[$key] ?? null) : getenv($key));

        $actionsMode = $read('GITHUB_ACTIONS') === 'true';
        $repository = self::validRepository($read('GITHUB_REPOSITORY'));

        return new self(
            actionsMode: $actionsMode,
            repositoryOwner: $repository?->owner,
            repositoryName: $repository?->name,
            sha: self::validSha($read('GITHUB_SHA')),
            serverUrl: self::validUrl($read('GITHUB_SERVER_URL')) ?? $configServerUrl,
            apiUrl: self::validUrl($read('GITHUB_API_URL')) ?? $configApiUrl,
        );
    }

    public function hasRepository(): bool
    {
        return $this->repositoryOwner !== null && $this->repositoryName !== null;
    }

    public function repositorySlug(): ?string
    {
        return $this->hasRepository() ? "{$this->repositoryOwner}/{$this->repositoryName}" : null;
    }

    private static function clean(string|false|null $value): ?string
    {
        if ($value === false || $value === null) {
            return null;
        }

        // Reject control characters (including CR/LF — no header/log
        // injection via a crafted environment) up front, for every value
        // this class ever reads.
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * @return object{owner:string,name:string}|null
     */
    private static function validRepository(?string $value): ?object
    {
        if ($value === null || preg_match(self::REPOSITORY_PATTERN, $value) !== 1) {
            return null;
        }

        [$owner, $name] = explode('/', $value, 2);

        return (object) ['owner' => $owner, 'name' => $name];
    }

    private static function validSha(?string $value): ?string
    {
        return $value !== null && preg_match(self::SHA_PATTERN, $value) === 1 ? strtolower($value) : null;
    }

    private static function validUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        // No embedded credentials, no query/fragment trickery — only
        // scheme://host[:port][/path] is accepted as a base URI.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim($parts['path'] ?? '', '/');
    }
}
