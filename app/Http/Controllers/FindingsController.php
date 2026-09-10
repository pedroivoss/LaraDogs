<?php

namespace App\Http\Controllers;

use App\Audit\Findings\ActorType;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Http\Requests\UpdateFindingStatusRequest;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\FindingStatusHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin adapter: `show()` reads through existing model relations only;
 * `updateStatus()` delegates the ENTIRE transition — including which
 * statuses require a reason — to
 * {@see FindingLifecycleService}, never writing
 * `Finding::status` directly (see ADR-0010).
 */
final class FindingsController extends Controller
{
    public function show(Finding $finding): Response
    {
        $finding->load([
            'project',
            'occurrences' => fn ($query) => $query->orderByDesc('observed_at'),
            'occurrences.scan',
            'statusHistory' => fn ($query) => $query->orderByDesc('id'),
        ]);

        return Inertia::render('findings/show', [
            'finding' => $this->findingToArray($finding),
            'occurrences' => $finding->occurrences->map($this->occurrenceToArray(...))->all(),
            'status_history' => $finding->statusHistory->map($this->historyToArray(...))->all(),
        ]);
    }

    public function updateStatus(Finding $finding, UpdateFindingStatusRequest $request, FindingLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->transition(
                $finding,
                $request->status(),
                ActorType::User,
                actorIdentifier: $request->user()?->email,
                reason: $request->reason(),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Finding marked {$request->status()->value}."]);

        return back();
    }

    /**
     * @return array<string,mixed>
     */
    private function findingToArray(Finding $finding): array
    {
        return [
            'id' => $finding->public_id,
            'rule_id' => $finding->rule_id,
            'analyzer_id' => $finding->analyzer_id,
            'category' => $finding->category->value,
            'severity' => $finding->severity->value,
            'confidence' => $finding->confidence->value,
            'status' => $finding->status->value,
            'status_reason' => $finding->status_reason,
            'title' => $finding->title,
            'description' => $finding->description,
            'impact' => $finding->impact,
            'recommendation' => $finding->recommendation,
            'cwe' => $finding->cwe,
            'cve' => $finding->cve,
            'references' => $finding->references,
            'first_seen_at' => $finding->first_seen_at->toIso8601String(),
            'last_seen_at' => $finding->last_seen_at->toIso8601String(),
            'project' => [
                'id' => $finding->project->public_id,
                'name' => $finding->project->name,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function occurrenceToArray(FindingOccurrence $occurrence): array
    {
        return [
            'file_path' => $occurrence->file_path,
            'line_start' => $occurrence->line_start,
            'line_end' => $occurrence->line_end,
            'code_snippet' => $occurrence->code_snippet,
            'context_code' => $occurrence->context_code,
            'rule_version' => $occurrence->rule_version,
            'analyzer_version' => $occurrence->analyzer_version,
            'observed_at' => $occurrence->observed_at->toIso8601String(),
            'scan' => [
                'id' => $occurrence->scan->public_id,
                'status' => $occurrence->scan->status->value,
                'started_at' => $occurrence->scan->started_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function historyToArray(FindingStatusHistory $history): array
    {
        return [
            'previous_status' => $history->previous_status?->value,
            'new_status' => $history->new_status->value,
            'reason' => $history->reason,
            'actor_type' => $history->actor_type->value,
            'actor_identifier' => $history->actor_identifier,
            'created_at' => $history->created_at->toIso8601String(),
        ];
    }
}
