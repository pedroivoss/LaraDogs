<?php

namespace App\Http\Controllers;

use App\Audit\Findings\ActorType;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Remediation\FindingRemediationService;
use App\Console\Commands\CreateOwnerCommand;
use App\Http\Controllers\Settings\UsersController;
use App\Http\Requests\UpdateFindingStatusRequest;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\FindingStatusHistory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin adapter: `show()` reads through existing model relations only;
 * `updateStatus()` delegates the ENTIRE transition — including which
 * statuses require a reason — to
 * {@see FindingLifecycleService}, never writing
 * `Finding::status` directly (see ADR-0010).
 *
 * Phase 12.1: `status_history.actor_identifier` is free-form text captured
 * at transition time (there is no `user_id` FK — see ADR-0010 and this
 * class's own history), so "is this actor the Owner" is decided by
 * comparing it against the CURRENT Owner's email — the single-Owner
 * invariant ({@see CreateOwnerCommand}) makes that
 * comparison exact without resolving each historical actor to a user row.
 * A non-Owner viewer never receives the Owner's identity, matching the
 * invisibility {@see UsersController}
 * already establishes elsewhere; every other actor's identity is shown
 * exactly as before — only the Owner is special-cased.
 */
final class FindingsController extends Controller
{
    public function show(Finding $finding, FindingRemediationService $remediation, Request $request): Response
    {
        $finding->load([
            'project',
            'occurrences' => fn ($query) => $query->orderByDesc('observed_at'),
            'occurrences.scan',
            'statusHistory' => fn ($query) => $query->orderByDesc('id'),
        ]);

        $viewer = $request->user();
        $ownerEmail = User::query()->where('role', Role::Owner)->value('email');

        return Inertia::render('findings/show', [
            'finding' => $this->findingToArray($finding),
            'occurrences' => $finding->occurrences->map($this->occurrenceToArray(...))->all(),
            'status_history' => $finding->statusHistory->map(fn (FindingStatusHistory $history): array => $this->historyToArray($history, $ownerEmail, $viewer))->all(),
            // Phase 12: deterministic, guidance-only remediation plan — the
            // same read model the CLI and MCP render. Never persisted, never
            // applied; safe (https-only) references and sanitized evidence.
            'remediation' => fn (): array => $remediation->forFinding($finding)->toArray(),
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
            // Phase 12.1: the raw persisted `references` are untrusted and
            // unvalidated (could be `javascript:`/`data:`/credential-bearing);
            // this page never rendered them (dead prop) and the Remediation
            // section already carries the safe, SafeReference-filtered set —
            // so they are not sent to the client at all, never both forms.
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
    private function historyToArray(FindingStatusHistory $history, ?string $ownerEmail, User $viewer): array
    {
        $identifier = $history->actor_identifier;
        $isOwnerActor = $identifier !== null && $ownerEmail !== null && strcasecmp($identifier, $ownerEmail) === 0;
        $hideIdentity = $isOwnerActor && ! $viewer->isOwner();

        return [
            'previous_status' => $history->previous_status?->value,
            'new_status' => $history->new_status->value,
            'reason' => $history->reason,
            'actor_type' => $history->actor_type->value,
            'actor_identifier' => $hideIdentity ? null : $identifier,
            // Set only when identity is withheld — a safe, neutral label
            // that still says a real person acted, without naming who.
            'actor_label' => $hideIdentity ? 'Privileged user' : null,
            'created_at' => $history->created_at->toIso8601String(),
        ];
    }
}
