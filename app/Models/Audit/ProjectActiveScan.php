<?php

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The portable per-project concurrency mutex row — see the
 * `create_project_active_scans_table` migration's own docblock for the
 * full reasoning. `project_id` is both the primary key and the model
 * key here (not the usual auto-increment `id`), since at most one row
 * can ever exist per project by design.
 *
 * @property int $project_id
 * @property int $scan_id
 */
final class ProjectActiveScan extends Model
{
    protected $primaryKey = 'project_id';

    public $incrementing = false;

    protected $fillable = ['project_id', 'scan_id'];

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
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
