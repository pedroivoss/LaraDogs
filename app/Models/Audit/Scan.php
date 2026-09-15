<?php

namespace App\Models\Audit;

use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One immutable audit run against a Project — never edited/reused to
 * represent a new execution; every run creates a new Scan row. Carries a
 * `project_profile` JSON snapshot (Phase 1's `ProjectProfile` at the time
 * of the scan) so it stays self-describing independent of the project's
 * current detected stack.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property ScanStatus $status
 * @property ScanOrigin $origin
 * @property int|null $initiated_by_user_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $running_at
 * @property CarbonImmutable|null $heartbeat_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property array<string,mixed> $project_profile
 * @property array<string,mixed>|null $environment
 * @property array<string,mixed>|null $findings_summary
 */
final class Scan extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id', 'status', 'origin', 'initiated_by_user_id', 'started_at',
        'running_at', 'heartbeat_at', 'finished_at', 'duration_ms',
        'laradogs_version', 'source_revision', 'project_profile', 'environment',
        'findings_summary',
    ];

    protected $casts = [
        'status' => ScanStatus::class,
        'origin' => ScanOrigin::class,
        'started_at' => 'datetime',
        'running_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'finished_at' => 'datetime',
        'project_profile' => 'array',
        'environment' => 'array',
        'findings_summary' => 'array',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Internal provenance only — see the `add_execution_tracking_to_scans_table`
     * migration's own docblock on why this is never rendered as another
     * user's identity in any shared (Owner/Admin/User) UI.
     *
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ScanAnalyzerExecution, $this>
     */
    public function analyzerExecutions(): HasMany
    {
        return $this->hasMany(ScanAnalyzerExecution::class);
    }

    /**
     * @return HasMany<FindingOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(FindingOccurrence::class);
    }
}
