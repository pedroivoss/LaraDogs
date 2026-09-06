<?php

namespace App\Models\Audit;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Execution\AnalyzerExecution;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Findings\Ingestion\FindingReconciler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persisted snapshot of one {@see AnalyzerExecution}
 * (Phase 2) for a given Scan. This is the record
 * {@see FindingReconciler} relies on to know
 * whether it's actually safe to auto-resolve a finding that didn't
 * reappear — never inferred from the Scan merely existing.
 *
 * @property int $id
 * @property int $scan_id
 * @property string $analyzer_id
 * @property string $analyzer_name
 * @property AnalyzerCategory $category
 * @property ExecutionStatus $status
 * @property string|null $summary
 * @property array<int,array<string,mixed>>|null $diagnostics
 * @property int|null $duration_ms
 * @property string|null $note
 */
final class ScanAnalyzerExecution extends Model
{
    protected $fillable = [
        'scan_id', 'analyzer_id', 'analyzer_name', 'category', 'status',
        'summary', 'diagnostics', 'duration_ms', 'note',
    ];

    protected $casts = [
        'category' => AnalyzerCategory::class,
        'status' => ExecutionStatus::class,
        'diagnostics' => 'array',
    ];

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
