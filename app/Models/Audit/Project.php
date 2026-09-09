<?php

namespace App\Models\Audit;

use App\Audit\Projects\Query\ProjectListQuery;
use App\Audit\Projects\RegisterProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Something auditable registered with LaraDogs. Persistence only for now —
 * no GitHub integration, no multi-tenancy, no remote cloning (see
 * ADR-0010's scope notes).
 *
 * `path` is unique (see the `add_unique_constraint_to_projects_path`
 * migration) and always stored realpath-resolved — see
 * {@see RegisterProject}, the only place a `Project`
 * row should be created.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $path
 * @property-read Scan|null $latestScan
 * @property-read int $open_findings_count only present when loaded via
 *                `withCount(['findings as open_findings_count' => ...])`
 *                — see {@see ProjectListQuery}
 */
final class Project extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'path'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return HasMany<Scan, $this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /**
     * The most recently STARTED scan (running, completed, or failed) —
     * portable across SQLite/MySQL/MariaDB/PostgreSQL via Eloquent's
     * `ofMany()` correlated-subquery join (no vendor-specific window
     * function). Used by the project list/summary query layer to avoid
     * N+1 queries — see `App\Audit\Projects\Query`.
     *
     * @return HasOne<Scan, $this>
     */
    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->latestOfMany('started_at');
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }
}
