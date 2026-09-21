<?php

namespace App\Models\Audit;

use App\Audit\QualityGates\QualityGateOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The immutable Quality Gate evaluation of one scan (Phase 8). Created
 * once by `QualityGateResultRecorder`, never updated afterwards — a later
 * policy change never rewrites it.
 *
 * @property int $id
 * @property int $project_id
 * @property int $scan_id
 * @property QualityGateOutcome $outcome
 * @property int $policy_revision
 * @property array<string,mixed> $policy_snapshot
 * @property int|null $baseline_scan_id
 * @property int $rules_total
 * @property int $rules_failed
 * @property int $rules_indeterminate
 * @property CarbonImmutable $evaluated_at
 */
final class QualityGateResult extends Model
{
    protected $fillable = [
        'project_id', 'scan_id', 'outcome', 'policy_revision', 'policy_snapshot',
        'baseline_scan_id', 'rules_total', 'rules_failed', 'rules_indeterminate', 'evaluated_at',
    ];

    protected $casts = [
        'outcome' => QualityGateOutcome::class,
        'policy_snapshot' => 'array',
        'evaluated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function baselineScan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'baseline_scan_id');
    }

    /**
     * @return HasMany<QualityGateRuleResult, $this>
     */
    public function ruleResults(): HasMany
    {
        return $this->hasMany(QualityGateRuleResult::class)->orderBy('position');
    }
}
