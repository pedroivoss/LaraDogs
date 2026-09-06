<?php

namespace App\Models\Audit;

use App\Audit\Findings\Redaction\EvidenceRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The evidence observed for a {@see Finding} in ONE specific {@see Scan} —
 * kept separate from Finding so a line moving, or code being reformatted,
 * never overwrites older evidence. `code_snippet`/`context_code` are
 * expected to already be redacted by
 * {@see EvidenceRedactor} before this row is
 * written — see docs/auditing/findings-lifecycle.md.
 *
 * @property int $id
 * @property int $finding_id
 * @property int $scan_id
 * @property string|null $file_path
 * @property int|null $line_start
 * @property int|null $line_end
 * @property string|null $code_snippet
 * @property string|null $context_code
 * @property array<string,mixed>|null $evidence
 * @property string|null $rule_version
 * @property string|null $analyzer_version
 * @property CarbonImmutable $observed_at
 */
final class FindingOccurrence extends Model
{
    protected $fillable = [
        'finding_id', 'scan_id', 'file_path', 'line_start', 'line_end',
        'code_snippet', 'context_code', 'evidence', 'rule_version',
        'analyzer_version', 'observed_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'observed_at' => 'datetime',
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
