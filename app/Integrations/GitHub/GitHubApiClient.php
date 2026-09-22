<?php

namespace App\Integrations\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The ONLY code that calls the GitHub REST API. A thin wrapper over
 * Laravel's own HTTP client (no new SDK dependency) — never a generic
 * client, only the one operation LaraDogs needs.
 *
 * - Authenticates via the `Authorization: Bearer <token>` HEADER only —
 *   never a token embedded in a URL (`https://TOKEN@github.com/...` is
 *   never constructed anywhere in this codebase).
 * - Sends `Accept: application/vnd.github+json`, a pinned
 *   `X-GitHub-Api-Version`, and an explicit `User-Agent` (GitHub requires
 *   one; an unset default risks being blocked).
 * - Bounded: a short connect/request timeout
 *   (`config('laradogs.ci.github_report_timeout_seconds')`), and the
 *   response body is size-checked before being parsed as JSON.
 * - Never retries a `POST` automatically — a create is not safely
 *   idempotent to retry blindly (a timeout does not tell us whether
 *   GitHub already created the resource before the connection dropped);
 *   see docs/integrations/github.md#idempotency for the reasoning. A rate
 *   limit (`403`/`429`) is recognized and never retried either.
 * - Never throws: every failure becomes a {@see GitHubCheckRunResult},
 *   and nothing here ever writes the token to a log or exception message.
 */
final readonly class GitHubApiClient
{
    private const int MAX_RESPONSE_BYTES = 1_000_000;

    public function __construct(
        private HttpFactory $http,
        private string $apiVersion,
        private string $userAgent,
        private int $timeoutSeconds,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function createCheckRun(GitHubContext $context, string $token, array $payload): GitHubCheckRunResult
    {
        $repository = $context->repositorySlug();

        if ($repository === null) {
            return GitHubCheckRunResult::failed('no_repository');
        }

        try {
            $response = $this->http
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => $this->apiVersion,
                    'User-Agent' => $this->userAgent,
                ])
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(min(5, $this->timeoutSeconds))
                ->post("{$context->apiUrl}/repos/{$repository}/check-runs", $payload);
        } catch (ConnectionException) {
            return GitHubCheckRunResult::failed('network_error');
        } catch (Throwable) {
            // Never let an unexpected client-library failure escape as an
            // exception that a caller might log with its full context.
            return GitHubCheckRunResult::failed('network_error');
        }

        return $this->parse($response);
    }

    private function parse(Response $response): GitHubCheckRunResult
    {
        if ($response->status() === 429 || ($response->status() === 403 && $response->header('x-ratelimit-remaining') === '0')) {
            return GitHubCheckRunResult::failed('rate_limited');
        }

        if ($response->status() === 403) {
            // Most commonly: a token without a GitHub App identity (a
            // classic/fine-grained PAT) — the Checks API only accepts
            // GitHub App-issued tokens (GITHUB_TOKEN in Actions qualifies).
            return GitHubCheckRunResult::failed('forbidden');
        }

        if ($response->status() === 404 || $response->status() === 410) {
            return GitHubCheckRunResult::failed('not_found');
        }

        if (! $response->successful()) {
            return GitHubCheckRunResult::failed('http_error');
        }

        $length = $response->header('Content-Length');

        if ($length !== '' && (int) $length > self::MAX_RESPONSE_BYTES) {
            return GitHubCheckRunResult::failed('invalid_response');
        }

        try {
            $body = $response->json();
        } catch (RequestException) {
            return GitHubCheckRunResult::failed('invalid_response');
        }

        $id = $body['id'] ?? null;
        $htmlUrl = $body['html_url'] ?? null;

        if (! is_int($id)) {
            return GitHubCheckRunResult::failed('invalid_response');
        }

        // GitHub itself returns this URL; only ever stored/shown when it is
        // actually an https:// URL on the same server we just talked to.
        $safeUrl = is_string($htmlUrl) && str_starts_with($htmlUrl, 'https://') ? $htmlUrl : null;

        return GitHubCheckRunResult::created($id, $safeUrl);
    }
}
