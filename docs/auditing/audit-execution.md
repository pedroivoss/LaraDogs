# Audit Execution & Scheduling (Phase 7.1.4)

**Status: Implemented.** Adds asynchronous audit execution — a Dashboard
"Run Audit" button, a queue worker, and an optional per-project schedule
(Disabled/Daily/Weekly/Monthly) — on top of the existing synchronous CLI
audit (`laradogs:project:audit`, Phase 3.2) and the Dashboard (Phase 7,
which deliberately shipped without a trigger — see
[`../dashboard.md`](../dashboard.md#audit-trigger-design-phase-714-async-queue--scheduler)).

## The single entry point: `RunProjectAudit`

CLI, Dashboard, and the scheduler all converge on **one** class,
`App\Audit\Projects\RunProjectAudit` — none of them duplicates discovery,
analyzer selection, persistence, reconciliation, or lifecycle logic:

```
CLI (laradogs:project:audit)  ─┐
Dashboard (POST .../audits)   ─┼─▶ RunProjectAudit ─▶ ScanRunner ─▶ ScanRecorder ─▶ Finding domain
Scheduler (dispatch-due-audits)┘
```

It's split into two steps because unattended execution cannot run
analyzers inside the dispatching HTTP request or scheduler tick:

- **`enqueue(Project, ScanOrigin, ?User $actor)`** — reserves a `Queued`
  `Scan` (see [Scan lifecycle](#scan-lifecycle) below) and returns
  immediately. Never runs an analyzer, never dispatches a queue job
  itself — safe to call from an HTTP request.
- **`execute(Scan)`** — takes an already-`Queued` scan and runs it
  (discovery → Engine → persistence) to `Completed`/`Failed`. This is
  what `App\Jobs\RunProjectAuditJob` calls from the queue worker.
- **`run(Project, ScanOrigin, ?User)`** — the CLI's synchronous
  convenience: `enqueue()` immediately followed by `execute()` in the
  same process, no real queue hop. This is why
  `laradogs:project:audit` keeps working exactly as before — see
  [CLI compatibility](#cli-compatibility).

## Scan lifecycle

`ScanStatus` gained `Queued` alongside the existing `Running` /
`Completed` / `Failed`:

```
Queued ──▶ Running ──▶ Completed
   │                └─▶ Failed
   └────────────────────▶ Failed   (reclaimed stale, or path disappeared before execution)
```

- **`Queued`**: a `Scan` row exists — with a real public ID — the moment
  an audit is requested, before discovery has even run. `project_profile`
  is an empty placeholder (the column is `NOT NULL`; the real snapshot
  isn't known yet). This is what lets the Dashboard show "Queued"
  immediately after clicking "Run Audit," without waiting on a worker.
- **`Running`**: set by `execute()` once discovery has actually
  succeeded — `running_at` and `heartbeat_at` are set, and
  `project_profile` is overwritten with the real, freshly-discovered
  profile (never the registration-time one).
- **`Completed`/`Failed`**: terminal, exactly as before Phase 7.1.4 —
  see [`findings-lifecycle.md`](findings-lifecycle.md).

No separate "execution history" table was introduced — the existing
`Scan`/`ScanStatus` schema already models exactly this lifecycle with
two small additive columns (`running_at`, `heartbeat_at`); inventing a
second, overlapping table would just be two sources of truth for the
same question ("what is this audit doing right now").

### Heartbeat

`started_at` alone is ambiguous for crash detection over a 15–30 minute
scan — a scan that's been `running` for an hour could be a legitimately
slow Semgrep pass or a worker that died three minutes in. `heartbeat_at`
is touched after every analyzer stage (via a plain `callable` threaded
through `AuditEngine::run()`/`execute()`, invoked once per completed
analyzer execution) — the Engine itself never depends on Eloquent
(ADR-0010); the heartbeat-writing happens one layer up, in `ScanRunner`,
which already depends on the Findings/persistence layer.

## Concurrency: a real, portable mutex

**A project can never have two active scans at once** — manual+manual,
manual+scheduled, scheduled+scheduled are all prevented, by the same
mechanism, without Redis or any distributed lock:

`project_active_scans` is a tiny table whose **primary key is
`project_id` itself** (not an autoincrement id) — one row per project,
maximum, enforced by the database's own primary-key constraint on every
supported engine (SQLite/MySQL/MariaDB/PostgreSQL). `ScanRecorder::enqueueScan()`
creates the `Scan` row and this mutex row in the same transaction; a
second, concurrent `enqueue()` call for the same project has its insert
rejected by the primary-key constraint, caught as a `QueryException`,
and turned into `RunProjectAuditOutcome::AlreadyRunning` — not a
"check, then insert" race like Phase 3.2's original advisory guard, a
real constraint. The mutex row is deleted the moment the scan reaches
`Completed`/`Failed` (`ScanRecorder::releaseActiveLock()`), from every
terminal path (success, an ingestion failure, an explicit `failScan()`,
and the job's own `failed()` hook).

`Project::activeScan()` reads this same table — the Dashboard's "Queued"/
"Running" badge and the scheduler's due-project check both ask the same
question the mutex itself answers, so they can never disagree with the
actual concurrency guarantee.

## Stale scan recovery: two separate thresholds

A crashed worker must never block a project forever, but "queued and
never picked up" and "running and the worker died mid-scan" are
different failure modes with very different normal durations —
`StaleScanReclaimer` treats them separately:

| Config key                                     | Default | Covers                                                                       |
| ---------------------------------------------- | ------- | ---------------------------------------------------------------------------- |
| `queued_scan_stale_threshold_seconds`          | 120s    | A `Queued` scan no worker ever picked up (worker down, misconfigured queue). |
| `stale_scan_threshold_seconds` (unchanged key) | 3600s   | A `Running` scan whose worker died mid-execution.                            |

A healthy worker picks up a queued job within seconds, so the queued
threshold stays short. The running threshold stays generous — matching
`laradogs.semgrep.timeout_seconds`'s own worst case — and now prefers
`heartbeat_at` over `started_at` when present, falling back to
`started_at` for scans created before this phase (no backfill migration
needed; both columns are nullable). Reclaiming only ever changes the
stale scan's own `status`/`finished_at` and releases its mutex row — it
never touches `Finding`/`FindingOccurrence`/`FindingStatusHistory`, and a
reclaimed scan is always `Failed`, never `Completed` — no coverage is
ever claimed for it. Both checked at the start of `enqueue()` (so a
stuck project is never blocked forever) — see
[`../dashboard.md`](../dashboard.md#stale-running-scan-decision-built-regardless-of-the-trigger-decision)
for the Phase 7 predecessor this replaces.

## Worker failure

A crashed worker must never let a scan be read as `Completed`. Three
independent layers guarantee this:

1. `ScanRecorder::completeScan()`'s own transaction: if persistence
   itself fails partway, the scan is marked `Failed` in its `catch`
   block, not left `Running`.
2. `RunProjectAuditJob::failed(?Throwable)` — Laravel's own
   failed-job hook, called once `$tries` is exhausted (see
   [Retries](#retries) below) — marks the scan `Failed` unless it's
   already terminal. This is the backstop for an exception anywhere else
   in the job's own glue code, outside `completeScan()`'s try/catch.
3. `StaleScanReclaimer` (above) is the last resort if even the `failed()`
   hook never runs (the process was killed outright, not merely thrown
   an exception) — the scan becomes `Failed` the next time anyone tries
   to audit that project.

No finding is ever auto-resolved based on an incomplete/failed scan —
this was already true (Phase 3.1's coverage-aware reconciliation) and is
unchanged; a `Failed` scan simply never reaches `FindingReconciler` at
all.

### Retries

`RunProjectAuditJob` sets `$tries = 1` — **deliberately no automatic
retry**. An audit can legitimately take 15–30 minutes (Semgrep); blindly
retrying an expensive, possibly-still-broken analysis is a worse default
than surfacing a clear `Failed` scan an operator (or the next scheduled/
manual run) can act on. `$timeout = 2000` seconds, comfortably above
`laradogs.semgrep.timeout_seconds`'s 1800s default — a queue timeout
shorter than the analyzer it's meant to let finish would kill a
legitimately still-running scan. If you raise
`LARADOGS_SEMGREP_TIMEOUT_SECONDS`, raise the worker's `--timeout` (see
`docker-compose.yml`'s `worker` service) and this job's `$timeout`
to match.

## Queue

`QUEUE_CONNECTION=database` — Laravel's own portable queue driver,
backed by the `jobs`/`failed_jobs` tables that already ship with this
application's default migrations. No Redis, no Horizon: the task
explicitly scoped this phase to avoid a new infrastructure dependency,
and the database queue is sufficient at self-hosted, single-instance
scale. `RunProjectAuditJob` carries only a plain integer `scanId` — never
a `ProjectProfile`, a filesystem tree, or an analyzer instance — so the
`jobs` table payload stays small and the worker always re-fetches fresh
`Scan`/`Project` state at execution time (Eloquent's `SerializesModels`
covers this automatically).

Dispatch always happens `->afterCommit()` — both from the Dashboard
controller and from the scheduler — so the worker can never observe a
`Scan` row from a transaction that hasn't actually committed yet.

## Manual audit (Dashboard)

`POST /projects/{project}/audits` (`ProjectAuditController::store()`) —
Owner/Admin only (the same `staff` middleware as project registration;
see [`../self-hosting.md`](../self-hosting.md#authorization-model)),
throttled (`throttle:6,1`). Calls `RunProjectAudit::enqueue()`, dispatches
`RunProjectAuditJob` on success, and always returns fast — analyzers
never run inside the request. The response is a plain redirect back to
the referring page with a flash toast ("Audit queued." or "An audit for
this project is already in progress.") — the HTTP status never encodes
success/failure of the underlying audit itself, only whether the request
was accepted.

Scan `origin` is `manual`, with `initiated_by_user_id` set to the
requesting user — answers "why did this scan happen," and is exposed on
Scan History, but is not a general audit-log framework and carries
nothing beyond this one field. `Scan::initiator()` must never be
rendered as another user's identity in a shared/multi-role view (Owner
privacy — see [`../self-hosting.md`](../self-hosting.md#owner-privacy)):
today nothing renders it at all.

## Dashboard polling

Project Detail uses Inertia React's built-in `usePoll()` hook (available
in the installed `@inertiajs/react`), started only while
`project.activeScan` is non-null and stopped the instant it becomes
`null` again — no WebSockets/Reverb/SSE, per this phase's explicit scope.
Polling does a partial reload (`only: [...]`) of just the fields that can
change, not a full page reload. Elapsed time for a `Running` scan is
computed client-side from `started_at`/`running_at` (ticking every
second in the browser) — the server is never asked to recompute or push
elapsed time.

## Scheduling

Each `Project` may optionally have an automatic audit schedule —
**Disabled** (the default for every project, including one just
registered — registering a project must never silently start periodic
resource consumption), **Daily**, **Weekly**, or **Monthly**. There is no
arbitrary cron expression UI, and no per-project time-of-day — the time
is one **instance-wide** setting
(`config('laradogs.projects.scheduled_audit_time')`, `HH:MM`, in the
application's own configured timezone, default `02:00`) — a deliberate
V1 scope limit rather than a per-project time picker. The Project
Settings UI is truthful about this: it shows "Next automatic audit"/
"Last automatic audit" computed from that shared time, never implying a
per-project time exists.

`App\Audit\Projects\ProjectAuditScheduler` is a pure, side-effect-free
date/time calculator (`nextRunAfter(AuditSchedule, ?dayOfWeek, ?dayOfMonth,
CarbonImmutable $after)`) — fully unit-testable against fixed reference
instants, no database access:

- **Daily** — the next occurrence of the configured time, today if still
  ahead, tomorrow otherwise. Never "24 hours from when you clicked
  Enable."
- **Weekly** — the next occurrence of a configured weekday + the
  configured time, bounded to at most 7 days ahead.
- **Monthly** — the next occurrence of a configured day-of-month + the
  configured time. A day-of-month that doesn't exist in the target month
  (31 in April, 29/30/31 in February) is **clamped to that month's actual
  last day**, not skipped and not overflowed into the next month — "day
  31" genuinely means "the last day of every month" rather than silently
  varying which months it fires in.

Storage: `projects.audit_schedule`, `audit_schedule_day_of_week`,
`audit_schedule_day_of_month`, `next_audit_at` (indexed — the scheduler's
due-project lookup is a single range scan against it), and
`last_scheduled_audit_at`. `next_audit_at` is recomputed immediately on
every schedule change (`ProjectAuditScheduleController::update()`) so the
Dashboard's "Next run" is never stale.

### `PUT /projects/{project}/audit-schedule`

Owner/Admin-only to mutate (same `staff` middleware); viewing the current
schedule on Project Detail is available to every active role, matching
the existing read/write split for the rest of the Dashboard. Validated
(`UpdateAuditScheduleRequest`): `audit_schedule` must be one of
`disabled`/`daily`/`weekly`/`monthly`; `audit_schedule_day_of_week`
required (0–6) only for `weekly`; `audit_schedule_day_of_month` required
(1–31) only for `monthly`.

## Scheduler dispatch

`laradogs:project:dispatch-due-audits` (registered via
`Schedule::command(...)->everyMinute()->withoutOverlapping()` in
`routes/console.php`, run continuously by the `scheduler` Docker service's
`php artisan schedule:work`) — a lightweight command
(`App\Audit\Projects\DispatchDueProjectAudits`) that:

1. Queries projects with a non-disabled schedule whose `next_audit_at` is
   due — one indexed range scan, no per-project discovery/profile
   recomputation, no N+1.
2. Calls `RunProjectAudit::enqueue()` for each — inheriting the exact same
   portable mutex described above, so a project that already has an
   active scan (manual or scheduled) is silently skipped, never
   double-enqueued.
3. Recomputes `next_audit_at` from **now**, not from the missed
   `next_audit_at` — if LaraDogs was down for several periods, this
   produces exactly **one** catch-up dispatch (from step 2, if the
   project wasn't already active) and resumes the regular cadence from
   here, never a backlog of every missed historical occurrence.
4. Advances `next_audit_at`/`last_scheduled_audit_at` regardless of
   whether step 2 actually enqueued anything — a skipped/failed dispatch
   must never disable future scheduling or create a retry storm.

The scheduler **never runs an analyzer itself** — it only enqueues, the
same way the Dashboard controller does; the `scheduler` Docker service
therefore never mounts `/projects` (least privilege — see
[Docker services](#docker-services) below). Scan `origin` is `scheduled`,
with `initiated_by_user_id` always `null` — there is no human actor to
attribute a scheduled run to.

## Docker services

Four services now make up the self-hosted profile (`app`/`db`, both
unchanged in shape from Phase 7.1.1, plus two new ones):

| Service     | Command                                                        | Mounts `/projects`?        | Purpose                                                  |
| ----------- | -------------------------------------------------------------- | -------------------------- | -------------------------------------------------------- |
| `worker`    | `php artisan queue:work --timeout=2000 --tries=1 --memory=256` | yes (`:ro`, same as `app`) | Consumes `RunProjectAuditJob` — runs the real analyzers. |
| `scheduler` | `php artisan schedule:work`                                    | no                         | Ticks every minute; only ever enqueues, never audits.    |

Both build from the same image as `app` and use
`LARADOGS_SKIP_MIGRATIONS=true` + `depends_on: app: condition:
service_healthy` — exactly one container (`app`) ever runs migrations,
avoiding a real three-container migration race against a fresh database.
Neither publishes a host port. The `worker`'s `--timeout=2000` is
deliberately above `laradogs.semgrep.timeout_seconds`'s 1800s default —
see [Retries](#retries) above; if you raise the Semgrep timeout, raise
this too. Both are `restart: unless-stopped`, same as `app`/`db`; queued
jobs survive a worker restart because they're persisted in MySQL (the
`database` queue driver), not held in the worker process's memory.

**Neither service inherits the Dockerfile's `HEALTHCHECK`** (it curls the
`app` service's own HTTP `/up` route — `worker`/`scheduler` never open an
HTTP port, so that check would always fail and misreport a healthy
process as `unhealthy` forever): both set `healthcheck: disable: true`
in `docker-compose.yml`. `restart: unless-stopped` already recovers a
genuinely crashed process; a permanently-red healthcheck would only be
misleading, not additionally protective.

## CLI compatibility

`docker compose exec app php artisan laradogs:project:audit <PUBLIC_ID>`
keeps working exactly as before, **synchronously** — valuable for
debugging/scripted automation, so this phase deliberately did not change
its default behavior. Internally it now goes through
`RunProjectAudit::run()` (`enqueue()` + `execute()` in the same process,
scan `origin` = `cli`) rather than a single-step `startScan()`, but the
observable behavior — exit code, output, "already running" diagnostic —
is unchanged, other than the diagnostic now reporting the conflicting
scan's actual status (`queued` or `running`) rather than assuming
`running`.

## Failure/missed-schedule semantics summary

- A failed manual or scheduled audit never disables that project's
  future scheduling.
- A missed schedule (LaraDogs was down) produces exactly one catch-up
  run, never a backlog.
- The scheduler ticking multiple times while a project is still due
  enqueues at most once — the same mutex that protects manual+manual
  concurrency also protects this case, with no extra logic.
- A `Failed`/incomplete scan never implies the project is clean — the
  existing fail-closed coverage semantics (Phase 3.1) are completely
  unchanged; async execution introduces no new way for a finding to be
  silently auto-resolved.

## Out of scope (this phase)

Push notifications (email/Slack/webhook) on scan completion, WebSockets/
Reverb/SSE, Redis (as a hard requirement), arbitrary cron expressions,
per-project schedule TIME, a general audit-log framework, quality gates,
new analyzers, Git/CI/MCP integration.

## Tests

`tests/Feature/Audit/Projects/RunProjectAuditTest.php` (concurrency,
both stale thresholds, path-disappears-mid-flight),
`tests/Feature/Audit/Projects/ProjectAuditSchedulerTest.php` (pure
daily/weekly/monthly/edge-day calculation),
`tests/Feature/Audit/Projects/DispatchDueProjectAuditsTest.php`
(dispatch/skip/catch-up-once), `tests/Feature/Jobs/RunProjectAuditJobTest.php`
(a **real** `database` queue job, dispatched and processed by a real
`queue:work --once`, plus the `failed()` backstop), and
`tests/Feature/Projects/ProjectAuditControllerTest.php`/
`ProjectAuditScheduleControllerTest.php` (authorization, fast HTTP
response, duplicate-dispatch prevention, origin/actor persistence,
default-disabled). All use synthetic fixtures under
`tests/Fixtures/discovery/` — never a real registered project.
