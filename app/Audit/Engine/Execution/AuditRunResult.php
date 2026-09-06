<?php

namespace App\Audit\Engine\Execution;

use App\Audit\Engine\Plan\AuditPlan;
use DateTimeImmutable;
use JsonSerializable;

/**
 * The complete, normalized outcome of one audit run: the plan that was
 * executed (for traceability — every item, including ones that never
 * ran) plus one {@see AnalyzerExecution} per item, in the same order.
 */
final readonly class AuditRunResult implements JsonSerializable
{
    /**
     * @param  list<AnalyzerExecution>  $executions
     */
    public function __construct(
        public string $runId,
        public AuditPlan $plan,
        public array $executions,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public int $durationMs,
    ) {}

    /**
     * @return list<AnalyzerExecution>
     */
    public function withStatus(ExecutionStatus $status): array
    {
        return array_values(array_filter(
            $this->executions,
            fn (AnalyzerExecution $execution): bool => $execution->status === $status,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'run_id' => $this->runId,
            'plan' => $this->plan,
            'executions' => $this->executions,
            'started_at' => $this->startedAt->format(DateTimeImmutable::ATOM),
            'finished_at' => $this->finishedAt->format(DateTimeImmutable::ATOM),
            'duration_ms' => $this->durationMs,
        ];
    }
}
