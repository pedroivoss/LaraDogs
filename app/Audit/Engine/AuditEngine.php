<?php

namespace App\Audit\Engine;

use App\Audit\Engine\Execution\AnalyzerExecution;
use App\Audit\Engine\Execution\AnalyzerResult;
use App\Audit\Engine\Execution\AuditRunResult;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Plan\AuditPlan;
use App\Audit\Engine\Plan\AuditPlanItem;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use DateTimeImmutable;
use Throwable;

/**
 * Orchestrates Analyzer Registry -> applicability/availability -> AuditPlan
 * -> execution -> AuditRunResult. Pure PHP: no HTTP, no Eloquent, no MCP,
 * no Docker, no dependency on any concrete Analyzer implementation. See
 * docs/auditing/audit-engine.md for the full lifecycle and security
 * boundary.
 */
final readonly class AuditEngine
{
    public function __construct(private AnalyzerRegistry $registry) {}

    /**
     * Builds the plan without running anything — every registered
     * analyzer's applicability (and, if applicable, availability) is
     * evaluated, but `run()` is never called here.
     */
    public function plan(AuditContext $context): AuditPlan
    {
        $items = [];

        foreach ($this->registry->all() as $analyzer) {
            $applicability = $analyzer->applicability($context->profile);

            if (! $applicability->isApplicable()) {
                $items[] = AuditPlanItem::notApplicable($analyzer, $applicability);

                continue;
            }

            $availability = $analyzer->availability($context);

            if (! $availability->isAvailable()) {
                $items[] = AuditPlanItem::unavailable($analyzer, $applicability, $availability);

                continue;
            }

            $items[] = AuditPlanItem::planned($analyzer, $applicability, $availability);
        }

        return new AuditPlan($items);
    }

    /**
     * Builds a plan and executes it in one call.
     */
    public function run(AuditContext $context): AuditRunResult
    {
        return $this->execute($this->plan($context), $context);
    }

    /**
     * Executes an already-built plan. The plan must have been built from
     * this same engine's registry — analyzers are looked up by id at
     * execution time, not carried inside the plan itself (see
     * {@see AuditPlanItem}).
     */
    public function execute(AuditPlan $plan, AuditContext $context): AuditRunResult
    {
        $startedAt = new DateTimeImmutable;
        $clockStart = hrtime(true);

        $executions = [];
        $stopExecuting = false;

        foreach ($plan->items as $item) {
            if (! $item->willExecute()) {
                $executions[] = AnalyzerExecution::fromPlanItem($item);

                continue;
            }

            if ($stopExecuting) {
                $executions[] = AnalyzerExecution::skipped(
                    $item,
                    'Skipped after a previous analyzer failed (continue_on_failure is false).',
                );

                continue;
            }

            $execution = $this->runOne($item, $context);
            $executions[] = $execution;

            if (! $context->settings->continueOnFailure
                && in_array($execution->status, [ExecutionStatus::Failed, ExecutionStatus::TimedOut], true)) {
                $stopExecuting = true;
            }
        }

        $durationMs = (int) ((hrtime(true) - $clockStart) / 1_000_000);
        $finishedAt = new DateTimeImmutable;

        return new AuditRunResult($context->runId, $plan, $executions, $startedAt, $finishedAt, $durationMs);
    }

    private function runOne(AuditPlanItem $item, AuditContext $context): AnalyzerExecution
    {
        $analyzer = $this->registry->get($item->id);

        if ($analyzer === null) {
            // Plan/registry mismatch (executing a plan built from a
            // different registry) — normalize to Failed instead of
            // throwing, so one bad item can't abort the whole run.
            return AnalyzerExecution::fromResult(
                $item,
                AnalyzerResult::failed("Analyzer [{$item->id}] is not registered in this engine's registry."),
                0,
            );
        }

        $clockStart = hrtime(true);

        try {
            $result = $analyzer->run($context);
        } catch (Throwable $exception) {
            $durationMs = (int) ((hrtime(true) - $clockStart) / 1_000_000);

            return AnalyzerExecution::fromException($item, $exception, $durationMs);
        }

        $durationMs = (int) ((hrtime(true) - $clockStart) / 1_000_000);

        return AnalyzerExecution::fromResult($item, $result, $durationMs);
    }
}
