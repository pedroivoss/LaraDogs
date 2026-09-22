<?php

namespace App\Models\Integrations;

use App\Models\Audit\Scan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That a Scan's Quality Gate result was reported to GitHub as a Check Run
 * (Phase 10) — bounded, GitHub-returned facts only (id, its own URL,
 * the conclusion LaraDogs sent). Never a response blob, never a token,
 * never part of the Finding/Scan/QualityGate domain itself: deleting this
 * row (or the whole table) can never change what LaraDogs observed or
 * decided, only whether GitHub was told about it.
 *
 * @property int $id
 * @property int $scan_id
 * @property int $check_run_id
 * @property string|null $html_url
 * @property string $conclusion
 * @property CarbonImmutable $reported_at
 */
final class GitHubCheckReport extends Model
{
    protected $table = 'github_check_reports';

    protected $fillable = ['scan_id', 'check_run_id', 'html_url', 'conclusion', 'reported_at'];

    protected $casts = [
        'reported_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
