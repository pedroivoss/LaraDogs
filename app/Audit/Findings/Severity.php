<?php

namespace App\Audit\Findings;

/**
 * How bad a finding is *if it's real* — independent of {@see Confidence},
 * which tracks how sure LaraDogs is that it's real at all. See
 * docs/auditing/severity.md.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    /**
     * The source itself did not report a severity for a real finding — e.g.
     * a Composer security advisory whose upstream `severity` field is
     * `null`. Distinct from {@see Info}, which means "not a problem"; this
     * means "is a problem, magnitude not stated by the source." Never
     * assigned by LaraDogs guessing a severity — only when the underlying
     * data genuinely has none. See docs/auditing/severity.md.
     */
    case Unknown = 'unknown';

    /**
     * THE single trusted severity ordering (Critical > High > Medium > Low
     * > Info) — anything that compares severities (Quality Gates, Phase 8)
     * must use this, never re-derive its own order. `Unknown` has NO rank
     * on purpose: it is not "below Info", it is "magnitude not stated",
     * and must never sort as harmless. See {@see isAtOrAbove()}.
     */
    public function rank(): ?int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Info => 1,
            self::Unknown => null,
        };
    }

    /**
     * Whether this severity is at or above `$threshold`. `Unknown`
     * (unstated magnitude of a real problem) is treated as meeting EVERY
     * threshold — LaraDogs cannot prove it is below any of them, and a
     * gate must fail closed rather than let it slip under "at or above
     * High". `$threshold` itself must be a ranked severity.
     */
    public function isAtOrAbove(self $threshold): bool
    {
        $thresholdRank = $threshold->rank();

        if ($thresholdRank === null) {
            throw new \InvalidArgumentException('An unranked severity cannot be used as a threshold.');
        }

        return $this->rank() === null || $this->rank() >= $thresholdRank;
    }

    /**
     * Severities that carry a rank, most severe first — the values a
     * threshold may take.
     *
     * @return list<self>
     */
    public static function ranked(): array
    {
        return [self::Critical, self::High, self::Medium, self::Low, self::Info];
    }
}
