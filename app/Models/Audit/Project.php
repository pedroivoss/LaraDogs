<?php

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something auditable registered with LaraDogs. Persistence only for now —
 * no GitHub integration, no multi-tenancy, no remote cloning (see
 * ADR-0010's scope notes).
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $path
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
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }
}
