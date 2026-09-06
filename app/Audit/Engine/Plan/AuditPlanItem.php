<?php

namespace App\Audit\Engine\Plan;

use App\Audit\Engine\Contracts\Analyzer;
use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Contracts\Applicability;
use App\Audit\Engine\Contracts\Availability;
use App\Audit\Engine\Execution\ExecutionStatus;
use JsonSerializable;

/**
 * One analyzer's inspectable slot in an {@see AuditPlan} — a snapshot of
 * its identity plus the applicability/availability decision that was made
 * for it, built BEFORE any execution happens. Never holds a reference to
 * the live {@see Analyzer} instance: a plan is plain, JSON-safe data, safe
 * to show in a CLI, dashboard, or MCP response without leaking behavior.
 */
final readonly class AuditPlanItem implements JsonSerializable
{
    private function __construct(
        public AnalyzerId $id,
        public string $name,
        public AnalyzerCategory $category,
        public Applicability $applicability,
        public ?Availability $availability,
        public ExecutionStatus $status,
    ) {}

    public static function planned(Analyzer $analyzer, Applicability $applicability, Availability $availability): self
    {
        return new self($analyzer->id(), $analyzer->name(), $analyzer->category(), $applicability, $availability, ExecutionStatus::Planned);
    }

    public static function notApplicable(Analyzer $analyzer, Applicability $applicability): self
    {
        return new self($analyzer->id(), $analyzer->name(), $analyzer->category(), $applicability, null, ExecutionStatus::NotApplicable);
    }

    public static function unavailable(Analyzer $analyzer, Applicability $applicability, Availability $availability): self
    {
        return new self($analyzer->id(), $analyzer->name(), $analyzer->category(), $applicability, $availability, ExecutionStatus::Unavailable);
    }

    public function willExecute(): bool
    {
        return $this->status === ExecutionStatus::Planned;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->value,
            'applicability' => $this->applicability,
            'availability' => $this->availability,
            'status' => $this->status->value,
        ];
    }
}
