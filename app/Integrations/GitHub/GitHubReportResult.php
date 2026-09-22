<?php

namespace App\Integrations\GitHub;

/**
 * What {@see RecordGitHubCheckRun} did — surfaced in the CI JSON envelope's
 * `github` block. `reported` distinguishes an actual new Check Run from an
 * idempotent replay ({@see reason} `already_reported`); a `false` value is
 * NEVER a reason to change the CI command's own exit code (see
 * docs/integrations/github.md#failure-isolation).
 */
final readonly class GitHubReportResult
{
    private function __construct(
        public bool $reported,
        public ?string $reason,
        public ?int $checkRunId,
        public ?string $htmlUrl,
    ) {}

    public static function reported(int $checkRunId, ?string $htmlUrl): self
    {
        return new self(true, null, $checkRunId, $htmlUrl);
    }

    /** An earlier call already reported this exact Scan — not a failure. */
    public static function alreadyReported(int $checkRunId, ?string $htmlUrl): self
    {
        return new self(true, 'already_reported', $checkRunId, $htmlUrl);
    }

    /**
     * @param  string  $reason  no_github_context, no_token, no_git_revision,
     *                          rate_limited, forbidden, not_found, http_error,
     *                          network_error, invalid_response
     */
    public static function notReported(string $reason): self
    {
        return new self(false, $reason, null, null);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'reported' => $this->reported,
            'reason' => $this->reason,
            'check_run_url' => $this->htmlUrl,
        ];
    }
}
