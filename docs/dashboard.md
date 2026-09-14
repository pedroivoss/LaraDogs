# Dashboard (Phase 7)

**Status: Implemented.** The first authenticated web UI over the persisted
audit domain (Phase 3/3.2) — Projects, Project Detail, a server-side
paginated/filtered Findings browser, Scan History, Scan Detail, and
Finding Detail with lifecycle status actions. No dashboard existed before
this phase beyond the starter kit's own placeholder `/dashboard` page.

## Architecture: the Dashboard is an adapter, not a second audit engine

Every page is backed by the **already-complete** Phase 3.2 query/service
layer — nothing here re-implements audit orchestration, the finding
lifecycle, fingerprinting, or coverage logic:

```
Controller (thin)
    ↓
Query/Application service (App\Audit\Projects\Query\*, FindingLifecycleService)
    ↓
Domain/Persistence (Project/Scan/Finding/... — Phase 3)
```

- **Reads** go through `ProjectListQuery`, `ProjectSummaryQuery`,
  `ScanHistoryQuery`, `CurrentFindingsQuery` — extended (not duplicated)
  with pagination methods this phase genuinely needed (see
  [Query layer changes](#query-layer-changes) below) — plus one genuinely
  new cross-project aggregate, `DashboardSummaryQuery` (see
  [Dashboard home](#dashboard-home)).
- **The one mutation** (finding status transitions) goes through
  `FindingLifecycleService::transition()` — the same, unmodified service
  the CLI/tests already used. No controller writes `Finding::status`
  directly.
- No controller calls an `Analyzer` or `ScanRunner`/`RunProjectAudit`
  directly to execute a NEW scan — see
  [Audit trigger design](#audit-trigger-design-cli-only-this-phase).

## Query layer changes

Two existing query methods gained an **additional, non-breaking**
paginated sibling — the plain unbounded methods (`forProject()`,
`recentFor()`) are unchanged and still used exactly as before by their
existing tests/potential future MCP callers that genuinely want
everything:

- `CurrentFindingsQuery::paginateForProject(Project, ?FindingFilters, int $perPage = 25): LengthAwarePaginator`
- `ScanHistoryQuery::paginateFor(Project, int $perPage = 20): LengthAwarePaginator`

Both read the current page from the request's own `page` query parameter
(Laravel's standard convention) and call `->withQueryString()` so
pagination links preserve active filters.

One genuinely new query was added — `DashboardSummaryQuery` — because
nothing existing answers "across every registered project" (`ProjectListQuery`
is per-project rows; `ProjectSummaryQuery` is one project's own summary).
It uses SQL aggregation (`count()`/`distinct()->count()`), never a full
`findings` table scan into PHP memory.

`ScanHistoryQuery::recentFor()`/`paginateFor()` order by
`started_at DESC, id DESC` — the `id` tiebreaker (a Phase 3.2 fix, applied
consistently here too) exists because `started_at` is only
second-precision; two scans in the same second would otherwise sort
nondeterministically.

## Routes

All under `['auth', 'verified']` — the exact same middleware the existing
`/dashboard` route already used; no new auth system, no roles/teams.

| Route                                                      | Name                  | Purpose                                |
| ---------------------------------------------------------- | --------------------- | -------------------------------------- |
| `GET /dashboard`                                           | `dashboard`           | Cross-project summary home             |
| `GET /projects`                                            | `projects.index`      | Project list                           |
| `GET /projects/{project:public_id}`                        | `projects.show`       | Project detail                         |
| `GET /projects/{project:public_id}/findings`               | `projects.findings`   | Findings browser (filtered, paginated) |
| `GET /projects/{project:public_id}/scans`                  | `projects.scans`      | Scan history (paginated)               |
| `GET /projects/{project:public_id}/scans/{scan:public_id}` | `projects.scans.show` | Scan detail (immutable snapshot)       |
| `GET /findings/{finding:public_id}`                        | `findings.show`       | Finding detail                         |
| `PATCH /findings/{finding:public_id}/status`               | `findings.status`     | Status transition (the only mutation)  |

Every route uses each model's **public ULID** (`{model:public_id}`
explicit binding syntax) — never the internal numeric `id`. Verified by
test: a project/finding's generated URL is asserted to differ from what a
numeric-id URL would look like, for every model that's routed to
directly. `ScanAnalyzerExecution`/`FindingOccurrence`/
`FindingStatusHistory` have no public identifier and are never routed to
directly — only ever shown nested inside a Scan/Finding page, matching
how the CLI already treats them.

`ProjectScansController::show()` does **not** rely solely on Laravel's
implicit nested-binding scoping for the project/scan relationship — it
explicitly verifies `$scan->project_id === $project->id` and 404s
otherwise. This is a deliberate, defensive choice for a security-relevant
boundary (cross-project data leakage) rather than trusting a framework
default.

## Dashboard home

Real, persisted data only — no hardcoded/fake figures anywhere. Shows:
total projects, projects with open findings, total open findings,
critical/high open findings, recent scans (across all projects), and
analyzer problems (`Failed`/`TimedOut` executions) among those recent
scans. **No health score.** No invented trend — there is no
historical-comparison semantics yet to compute one from (that's a future,
explicitly out-of-scope phase). An empty state (zero projects) explains
the CLI registration command rather than showing empty charts.

## Finding lifecycle actions

The finding detail page's "Change status" dialog lets an authenticated
user transition a finding through `FindingLifecycleService::transition()`,
passing `ActorType::User` with the authenticated user's email as
`actorIdentifier` — the **first real caller** of `ActorType::User` in
shipped code (previously reserved/unused, per that enum's own docblock).

- `UpdateFindingStatusRequest` validates only that `status` is a real
  `FindingStatus` value — it does **not** duplicate which statuses
  require a reason. That rule is enforced by
  `FindingLifecycleService::transition()` itself
  (`FindingStatus::requiresReason()`); the controller catches the
  resulting `InvalidArgumentException` and turns it into a normal Inertia
  validation error (`reason` field), so the server-side rule holds even
  if a client bypassed its own UI validation.
- The frontend also disables submission / shows the requirement inline
  (via the shared `FINDING_STATUSES_REQUIRING_REASON` constant in
  `resources/js/types/audit.ts`) — a UX convenience, not the actual
  enforcement boundary.
- Suppressed statuses (`accepted_risk`/`false_positive`/`ignored`) and
  their transitions are exercised exactly the same way the existing
  Phase 3.2 tests already proved were safe — this phase adds no new
  suppression semantics, only a UI for the existing ones.

## Audit trigger design (CLI-only this phase)

**Decision: the Dashboard does NOT trigger new audits.** Project Detail
shows the exact `laradogs:project:audit {id}` command to run instead.

Why, given the spec explicitly allowed either a queued job or disabling
Dashboard-triggered scanning:

1. **A synchronous HTTP-request-triggered audit is unsafe as a real
   design**, not just slow: Semgrep alone can take up to
   `laradogs.semgrep.timeout_seconds` (1800s default), and a real project
   with multiple analyzers can genuinely take ~30 minutes. An HTTP
   request held open that long will typically be killed by the browser,
   a reverse proxy, or the web server's own timeout well before
   completion — which would leave a `Scan` stuck `running`, i.e. it
   would _actively reproduce_ the exact stale-scan problem via a NEW,
   _more common_ path than the crashed-CLI-process case Phase 3.2 already
   documented.
2. **A queued job needs a worker actually running to not be silently
   broken.** `QUEUE_CONNECTION=database` is already configured and the
   `jobs` table migration already exists (Phase 0 starter kit) — dispatching
   to it would technically reuse existing infrastructure, not add new
   infrastructure in the Redis/Horizon sense. But no worker process is
   documented or running in this project's Docker setup today; a
   dispatched job with nobody consuming the queue means a user clicks
   "Run audit," sees a generic "queued" message, and then **nothing ever
   happens**, with no error surfaced — arguably a worse experience than a
   clear, honest "not available yet, here's the command."
3. The task's own spec explicitly permits this exact choice: _"OR disable
   Dashboard-triggered scanning and expose the CLI instruction."_

This was a genuine decision, not a shortcut — see
[Known limitations](#known-limitations) for what would need to exist
first (a documented, monitored worker process) before revisiting it.

## Stale-running-scan decision (built regardless of the trigger decision)

Even though the Dashboard doesn't trigger audits itself, the spec required
designing (and this phase implements) the smallest portable stale-scan
recovery mechanism as prerequisite groundwork — genuinely useful today
for the existing CLI-triggered workflow too, closing the Phase 3.2 known
limitation outright rather than leaving it purely theoretical.

**`App\Audit\Projects\StaleScanReclaimer`**: a `Scan` still `running`
after `config('laradogs.projects.stale_scan_threshold_seconds')` (default
3600s — deliberately generous headroom over the ~31-minute worst-case
legitimate scan duration at current default timeouts) since `started_at`
is treated as abandoned (the process that owned it crashed/was killed)
and reclaimed — marked `failed`, `finished_at` set — the next time
`RunProjectAudit::run()` is called for that project.

Meets every requirement given:

- **No Redis dependency** — a plain `WHERE started_at < ?` query.
- **Database-portable** — no vendor-specific SQL.
- **Deterministic** — a fixed, configurable threshold, not a heuristic.
- **No automatic corruption of historical data** — only the stale scan's
  own `status`/`finished_at` change; nothing else about it (its
  `project_profile` snapshot, `started_at`, etc.) is touched.
- **Never marks findings resolved merely because a worker died** —
  reclaiming NEVER calls `FindingReconciler` and never touches `Finding`/
  `FindingOccurrence`/`FindingStatusHistory` in any way.
- **Fail-closed** — the reclaimed scan is `failed`, never `completed`; no
  coverage is ever claimed for it.

**Known, accepted trade-off**: age-based classification can misclassify a
genuinely very slow (but still legitimately running) scan past the
threshold as stale — there is no heartbeat/PID tracking to distinguish
the two more precisely without new infrastructure, which this phase
deliberately does not add.

This mechanism is a prerequisite, not a green light on its own — a
worker-based async trigger is still not implemented (see above); this
just means the ONE identified blocker specific to "a scan dies mid-flight
and blocks the project forever" is now closed for whenever unattended
triggering (CI, a scheduler, a Git webhook) is eventually built.

## Project registration UI decision

**Implemented in Phase 7.1.2, exactly the way this section originally
anticipated it would need to be done safely.** Real UAT feedback showed
CLI-only registration was too much friction for normal use, but the
concern below was correct: a free-text path input would either mislead
users about "the LaraDogs server's filesystem" or scope-creep into a file
browser. The actual implementation avoids both — the Dashboard's "Add
Project" page (Owner/Admin-only, see `docs/self-hosting.md`'s
authorization model) never accepts a typed path at all. It lists only the DIRECT child
directories of a single configured root
(`App\Audit\Projects\ProjectDirectoryDiscovery`, `config('laradogs.projects.root')`,
`/projects` in the Docker profile) and the browser picks a directory NAME
from that list; the backend resolves the name back to a realpath-
contained absolute path (rejecting traversal and symlink escapes) before
handing it to the same `RegisterProject` service the CLI already uses —
no duplicated registration logic, no arbitrary filesystem access. CLI
registration (`laradogs:project:add`) remains fully supported as a
fallback — see `docs/self-hosting.md`.

## Docker path behavior

Documented on the Dashboard's own empty states and Project Detail's "Run a
new audit" card: the path shown/required is always the path as known
**inside the LaraDogs container**, not the host path — the same
distinction `docs/auditing/projects.md`'s own Docker section already
establishes for the CLI. No Docker socket access was added; nothing
auto-mounts a host directory.

## Empty/error states

- **No projects**: explains the CLI registration command, never implies
  "you have nothing to worry about."
- **No current findings** (for a project or after filtering): _"No
  current findings were reported by the analyzers that completed with
  sufficient coverage"_ — never _"your project is secure."_ When filters
  are active, the message distinguishes "no findings match these
  filters" from "no current findings at all."
- **Never scanned**: Project Detail shows "This project has not been
  scanned yet" for analyzer status, rather than an empty/misleading
  "clean" state.
- **Unknown project/finding/scan ID**: a plain 404, no stack trace
  (`APP_DEBUG=false` in production; verified via a real request in this
  phase's own manual check that no exception detail leaks).
- **A scan whose analyzer Failed/TimedOut**: always shown with its own
  distinct status badge — never rendered as if it were `Passed`.

## Accessibility & theming

- Every status/severity/confidence/coverage signal is rendered as a
  **labeled badge with text**, never a bare color — satisfied structurally
  (every badge component always renders its value as visible text) rather
  than needing a special-cased colorblind mode.
- `ConfidenceBadge` deliberately uses a completely different visual
  language (neutral dashed outline, opacity-only variation) from
  `SeverityBadge` (a red/amber/blue filled scale) specifically so
  "high confidence" can never be visually mistaken for "high severity."
- All new components use the existing `dark:` token system already
  established by the starter kit (`bg-background`, `text-muted-foreground`,
  etc., plus explicit `dark:` variants on the few new semantic colors
  introduced for severity/status) — no separate theme system was created.
  Existing `useAppearance()`/dark-mode toggle is untouched and works
  identically on every new page.
- Dialog/Select/DropdownMenu usage is entirely the existing Radix-based
  shadcn primitives already in the app — keyboard navigation, focus
  trapping, and visible focus states come from those unmodified.

## Known limitations

- **No Dashboard-triggered audits** — CLI only, see
  [Audit trigger design](#audit-trigger-design-cli-only-this-phase). A
  future phase could revisit this once a documented, monitored queue
  worker process exists.
- **Project registration UI is Owner/Admin-only** (Phase 7.1.2, roles
  refined in 7.1.3) — a deliberate policy, since it grants access to
  server-mounted filesystem paths under the configured project root; see
  `docs/self-hosting.md`'s authorization model. CLI registration
  (`laradogs:project:add`) has no such restriction.
- **Stale-scan reclaim is age-based, not heartbeat-based** — a
  legitimately very slow scan past the threshold is misclassified as
  abandoned; no PID/heartbeat tracking exists to do better without new
  infrastructure.
- **No health score, no charts, no trend lines** — deliberately, per this
  phase's own scope (no formula specified, no historical-comparison
  semantics built yet).
- **No MCP server, no Git integration, no quality gates** — none of these
  exist in this phase's code; explicitly out of scope.
- Live browser (Playwright) validation for this delivery was blocked by
  an unrelated concurrent session already holding the shared MCP browser
  profile lock — substituted with authenticated HTTP-level smoke tests
  against every route (with real seeded data) confirming correct status
  codes/no stack-trace leakage, a clean `tsc --noEmit`, a clean production
  build, and (the strongest evidence) Pest tests asserting exact Inertia
  prop values for every page.
