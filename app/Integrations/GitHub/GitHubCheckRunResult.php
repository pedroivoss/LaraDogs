<?php

namespace App\Integrations\GitHub;

/**
 * The outcome of ONE {@see GitHubApiClient::createCheckRun()} call —
 * never throws, so a GitHub API failure can never propagate into the CI
 * command's own exit code (see docs/integrations/github.md#failure-isolation).
 */
final readonly class GitHubCheckRunResult
{
    private function __construct(
        public bool $successful,
        public ?int $checkRunId,
        public ?string $htmlUrl,
        public ?string $failureReason,
    ) {}

    public static function created(int $checkRunId, ?string $htmlUrl): self
    {
        return new self(true, $checkRunId, $htmlUrl, null);
    }

    /**
     * @param  string  $reason  one of: rate_limited, forbidden, not_found,
     *                          http_error, network_error, invalid_response
     */
    public static function failed(string $reason): self
    {
        return new self(false, null, null, $reason);
    }
}
