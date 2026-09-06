<?php

namespace App\Models\Audit;

use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable, append-only lifecycle transition for a {@see Finding} —
 * created only through {@see FindingLifecycleService},
 * never written to directly. Scoped to identity/status events (created,
 * status changed — including auto-resolve and reopen/regression); a plain
 * re-observation with no status change is recorded as a
 * {@see FindingOccurrence} instead, not a history row here.
 *
 * @property int $id
 * @property int $finding_id
 * @property int|null $scan_id
 * @property FindingStatus|null $previous_status
 * @property FindingStatus $new_status
 * @property string|null $reason
 * @property ActorType $actor_type
 * @property string|null $actor_identifier
 * @property CarbonImmutable $created_at
 */
final class FindingStatusHistory extends Model
{
    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'finding_id', 'scan_id', 'previous_status', 'new_status', 'reason',
        'actor_type', 'actor_identifier',
    ];

    protected $casts = [
        'previous_status' => FindingStatus::class,
        'new_status' => FindingStatus::class,
        'actor_type' => ActorType::class,
    ];

    /**
     * @return BelongsTo<Finding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
