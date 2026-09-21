<?php

namespace App\Models\Audit;

use App\Audit\QualityGates\Policy\QualityGatePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Project's Quality Gate POLICY row (Phase 8) — never a result. A
 * project without a row has no gate (disabled). `policy` is read back only
 * through {@see policy()} (strict typed parsing), never as free-form
 * config.
 *
 * @property int $id
 * @property int $project_id
 * @property bool $enabled
 * @property int $revision
 * @property array<string,mixed>|null $policy
 * @property CarbonImmutable|null $updated_at
 */
final class ProjectQualityGate extends Model
{
    protected $fillable = ['project_id', 'enabled', 'revision', 'policy'];

    protected $casts = [
        'enabled' => 'boolean',
        'revision' => 'integer',
        'policy' => 'array',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function policy(): ?QualityGatePolicy
    {
        return $this->policy === null ? null : QualityGatePolicy::fromArray($this->policy);
    }
}
