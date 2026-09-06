<?php

namespace App\Models\Audit;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The stable, cross-scan LOGICAL IDENTITY of an issue — never a raw
 * scanner result, never a single observation. See ADR-0010 and
 * docs/auditing/findings-lifecycle.md.
 *
 * `fingerprint`/`fingerprint_version` correlate re-observations to this
 * row; they are NOT this row's primary key (`id` is) — see
 * {@see Fingerprinter} for why fingerprint
 * collisions must never be load-bearing for identity.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property string $fingerprint
 * @property string $fingerprint_version
 * @property string $rule_id
 * @property string $analyzer_id
 * @property AnalyzerCategory $category
 * @property Severity $severity
 * @property Confidence $confidence
 * @property string $title
 * @property string|null $description
 * @property string|null $impact
 * @property string|null $recommendation
 * @property string|null $cwe
 * @property string|null $cve
 * @property list<string>|null $references
 * @property array<string,mixed>|null $metadata
 * @property FindingStatus $status
 * @property string|null $status_reason
 * @property int $first_seen_scan_id
 * @property CarbonImmutable $first_seen_at
 * @property int $last_seen_scan_id
 * @property CarbonImmutable $last_seen_at
 */
final class Finding extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id', 'fingerprint', 'fingerprint_version', 'rule_id', 'analyzer_id',
        'category', 'severity', 'confidence', 'title', 'description', 'impact',
        'recommendation', 'cwe', 'cve', 'references', 'metadata', 'status',
        'status_reason', 'first_seen_scan_id', 'first_seen_at', 'last_seen_scan_id',
        'last_seen_at',
    ];

    protected $casts = [
        'category' => AnalyzerCategory::class,
        'severity' => Severity::class,
        'confidence' => Confidence::class,
        'status' => FindingStatus::class,
        'references' => 'array',
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function firstSeenScan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'first_seen_scan_id');
    }

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function lastSeenScan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'last_seen_scan_id');
    }

    /**
     * @return HasMany<FindingOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(FindingOccurrence::class);
    }

    /**
     * @return HasMany<FindingStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(FindingStatusHistory::class);
    }
}
