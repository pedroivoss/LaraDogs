<?php

namespace App\Models\Audit;

use App\Audit\QualityGates\GateRuleId;
use App\Audit\QualityGates\QualityGateOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One evaluated rule (per subject) of a {@see QualityGateResult}.
 *
 * @property int $id
 * @property int $quality_gate_result_id
 * @property int $position
 * @property GateRuleId $rule_id
 * @property string|null $subject
 * @property QualityGateOutcome $outcome
 * @property string $summary
 * @property string|null $observed
 * @property string|null $expected
 * @property string|null $analyzer_id
 * @property string|null $severity
 * @property int $finding_count
 * @property list<string>|null $finding_ids
 */
final class QualityGateRuleResult extends Model
{
    protected $fillable = [
        'quality_gate_result_id', 'position', 'rule_id', 'subject', 'outcome', 'summary',
        'observed', 'expected', 'analyzer_id', 'severity', 'finding_count', 'finding_ids',
    ];

    protected $casts = [
        'rule_id' => GateRuleId::class,
        'outcome' => QualityGateOutcome::class,
        'finding_ids' => 'array',
    ];

    /**
     * @return BelongsTo<QualityGateResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(QualityGateResult::class, 'quality_gate_result_id');
    }
}
