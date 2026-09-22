<?php

namespace App\Audit\Source\Git;

use App\Models\Audit\Scan;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * An immutable, normalized description of a project's Git source state at
 * one instant — deliberately minimal (no author name/email, no file lists,
 * no absolute host path, no credentials).
 *
 * `commitSha` is the FULL object id and is the only persisted revision
 * identity; {@see shortSha()} is presentation only. `dirty` is `true` when
 * the working tree differs from HEAD, counting tracked (staged or
 * unstaged) changes AND untracked, non-ignored files; ignored files and
 * submodule content are not considered (see docs/git/README.md).
 *
 * A snapshot is only a full statement of the audited source when
 * {@see isReproducible()} — a dirty tree (or a repository without commits)
 * is not fully identified by its SHA.
 */
final readonly class GitSnapshot
{
    public const int SHORT_SHA_LENGTH = 7;

    public function __construct(
        public GitRepositoryState $state,
        public ?string $commitSha = null,
        public ?string $branch = null,
        public bool $detached = false,
        public ?bool $dirty = null,
        public ?DateTimeImmutable $commitTimestamp = null,
        public ?string $commitSubject = null,
        public ?string $remoteOrigin = null,
        public ?string $unavailableReason = null,
    ) {}

    public static function notRepository(): self
    {
        return new self(GitRepositoryState::NotRepository);
    }

    public static function bare(): self
    {
        return new self(GitRepositoryState::Bare);
    }

    public static function unavailable(string $reason): self
    {
        return new self(GitRepositoryState::Unavailable, unavailableReason: $reason);
    }

    /**
     * The snapshot persisted on a scan, or null for a scan whose source was
     * never captured (pre-Phase-9) — history is never reconstructed from
     * the current filesystem.
     */
    public static function fromScan(Scan $scan): ?self
    {
        $state = $scan->source_type === null ? null : GitRepositoryState::tryFrom($scan->source_type);

        if ($state === null) {
            return null;
        }

        return new self(
            state: $state,
            commitSha: $scan->source_revision,
            branch: $scan->source_branch,
            detached: (bool) $scan->source_detached,
            dirty: $scan->source_dirty,
            commitTimestamp: $scan->source_commit_at?->toDateTimeImmutable(),
            commitSubject: $scan->source_commit_subject,
            remoteOrigin: $scan->source_remote,
        );
    }

    public function isRepository(): bool
    {
        return $this->state === GitRepositoryState::Repository;
    }

    public function hasCommit(): bool
    {
        return $this->commitSha !== null;
    }

    public function shortSha(): ?string
    {
        return $this->commitSha === null ? null : substr($this->commitSha, 0, self::SHORT_SHA_LENGTH);
    }

    /**
     * True only when the full SHA alone identifies the source: a repository
     * with at least one commit and a clean working tree.
     */
    public function isReproducible(): bool
    {
        return $this->isRepository() && $this->hasCommit() && $this->dirty === false;
    }

    /**
     * The `scans.source_*` column values for this snapshot.
     *
     * @return array<string,mixed>
     */
    public function toScanAttributes(): array
    {
        return [
            'source_type' => $this->state->value,
            'source_revision' => $this->commitSha,
            'source_branch' => $this->branch,
            'source_detached' => $this->isRepository() ? $this->detached : null,
            'source_dirty' => $this->dirty,
            'source_commit_at' => $this->commitTimestamp === null ? null : CarbonImmutable::instance($this->commitTimestamp),
            'source_commit_subject' => $this->commitSubject,
            'source_remote' => $this->remoteOrigin,
        ];
    }
}
