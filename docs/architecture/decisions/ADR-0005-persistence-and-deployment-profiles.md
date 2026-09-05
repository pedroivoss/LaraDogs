# ADR-0005: Persistence Strategy and Deployment Profiles

## Status

Accepted for Phase 0 (database driver + Docker shape); the "Server" profile
and history/versioning guarantees are Proposed for later phases.

## Context

The product brief asks for two deployment profiles — "Personal" (easy
local start, simple storage) and "Server" (PostgreSQL, Redis, workers,
scheduler, reverse proxy, multi-project) — without over-building Docker in
Phase 0. It also requires that updating LaraDogs never destroys scan
history, and that persisted data be decoupled from the application
version.

## Decision

- **Phase 0 targets the Personal profile only.** Database driver is
  **SQLite** (`DB_CONNECTION=sqlite`), matching the Laravel installer
  default for new applications. Docker is a **single container**
  (`Dockerfile` + `docker-compose.yml`) running `php artisan serve`
  directly — no Nginx/PHP-FPM split, no queue worker container, no
  scheduler container. This matches "don't over-size Docker in this
  phase": there is no audit domain yet to justify workers or a scheduler.
- Docker volumes (`laradogs-database`, `laradogs-storage`) persist the
  SQLite file and `storage/` (logs, framework cache, sessions) across
  container recreation, since Personal-profile users are expected to
  `docker compose up`/`down` repeatedly without losing their data.
- **PostgreSQL support for the "Server" profile is deferred**, not
  implemented, not stubbed. Laravel's database layer (`config/database.php`)
  already supports switching `DB_CONNECTION` to `pgsql` without code
  changes, so this is a configuration decision to make later, not an
  abstraction to build now.
- **Scan immutability and history** (each audit run produces an immutable
  `Scan` record; comparing scans yields NEW/RESOLVED/UNCHANGED/REGRESSED)
  is explicitly **out of scope for Phase 0**. It depends on the `Finding`
  model ([ADR-0003](ADR-0003-finding-domain-model.md)) which doesn't exist
  yet. This ADR only records the constraint for when that work starts:
  schema migrations must be additive/versioned in a way that an app
  upgrade cannot silently drop or reinterpret historical scan data.

## Consequences

- A Phase 8+ implementer must design the `scans` table (and any migration
  changing it) with the "never destroy history on upgrade" constraint in
  mind from the first migration, not retrofitted later.
- Multi-project support (Server profile) will need a schema that scopes
  scans/findings by project from the start of Phase 3, even though only a
  single implicit project matters in Phase 0. This ADR does not design
  that schema — it flags that Phase 3 must not hardcode a single-project
  assumption that Phase 8/11 would have to unwind.
- Redis is not introduced in Phase 0. Queue driver is `database` (Laravel
  default for the starter kit), which is adequate until there's an actual
  workload (scanner execution) to queue.
