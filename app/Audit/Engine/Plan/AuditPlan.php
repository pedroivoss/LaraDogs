<?php

namespace App\Audit\Engine\Plan;

use JsonSerializable;

/**
 * An ordered, inspectable list of {@see AuditPlanItem}s — the outcome of
 * applicability/availability decisions for every registered analyzer,
 * built without running any of them. Building a plan never calls
 * `Analyzer::run()`.
 */
final readonly class AuditPlan implements JsonSerializable
{
    /**
     * @param  list<AuditPlanItem>  $items
     */
    public function __construct(public array $items) {}

    /**
     * @return list<AuditPlanItem>
     */
    public function toExecute(): array
    {
        return array_values(array_filter($this->items, fn (AuditPlanItem $item): bool => $item->willExecute()));
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items];
    }
}
