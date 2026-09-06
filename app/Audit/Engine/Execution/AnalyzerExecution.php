<?php

namespace App\Audit\Engine\Execution;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Plan\AuditPlanItem;
use JsonSerializable;
use Throwable;

/**
 * The normalized, post-execution outcome for one plan item — whether it
 * was actually run or not. This is what makes an analyzer that threw an
 * exception, one that reported failure, and one that was never run
 * (unavailable/not applicable/skipped) all representable the same way,
 * regardless of which analyzer produced which outcome.
 */
final readonly class AnalyzerExecution implements JsonSerializable
{
    public function __construct(
        public AnalyzerId $id,
        public string $name,
        public AnalyzerCategory $category,
        public ExecutionStatus $status,
        public ?AnalyzerResult $result,
        public ?int $durationMs,
        public ?string $note,
    ) {}

    /**
     * For a plan item that was never executed (NotApplicable/Unavailable):
     * carries its applicability/availability reason forward as the note.
     */
    public static function fromPlanItem(AuditPlanItem $item): self
    {
        $note = match ($item->status) {
            ExecutionStatus::NotApplicable => $item->applicability->reason,
            ExecutionStatus::Unavailable => $item->availability?->reason,
            default => null,
        };

        return new self($item->id, $item->name, $item->category, $item->status, null, null, $note);
    }

    public static function skipped(AuditPlanItem $item, string $reason): self
    {
        return new self($item->id, $item->name, $item->category, ExecutionStatus::Skipped, null, null, $reason);
    }

    public static function fromResult(AuditPlanItem $item, AnalyzerResult $result, int $durationMs): self
    {
        return new self($item->id, $item->name, $item->category, $result->status, $result, $durationMs, null);
    }

    /**
     * An analyzer that throws is normalized into a Failed execution with a
     * diagnostic describing the exception — it must never bubble up and
     * abort the rest of the audit run.
     */
    public static function fromException(AuditPlanItem $item, Throwable $exception, int $durationMs): self
    {
        $result = AnalyzerResult::failed(
            summary: 'Analyzer threw an unhandled exception.',
            diagnostics: [new AnalyzerDiagnostic(
                DiagnosticLevel::Error,
                sprintf('%s: %s', $exception::class, $exception->getMessage()),
            )],
        );

        return new self($item->id, $item->name, $item->category, ExecutionStatus::Failed, $result, $durationMs, null);
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
            'status' => $this->status->value,
            'result' => $this->result,
            'duration_ms' => $this->durationMs,
            'note' => $this->note,
        ];
    }
}
