# ADR-0005: Persistence Strategy and Deployment Profiles

## Status

**Partially Superseded (Phase 0.1).** The Docker/deployment-profile shape
decision below (single container, no Nginx/FPM split, no worker/scheduler
container in Phase 0) remains **Accepted** and unchanged.

The database-vendor decision — SQLite as _the_ driver, with PostgreSQL
support deferred exclusively to a future "Server" profile — is
**Superseded by [ADR-0007](ADR-0007-database-agnostic-persistence.md)**,
which makes LaraDogs' own persistence database-agnostic (SQLite, MySQL,
MariaDB, PostgreSQL) and decouples database vendor choice from deployment
profile. Read this ADR for the historical record of Phase 0's original
reasoning; read ADR-0007 for the current persistence decision.

The "Server" profile shape itself and history/versioning guarantees remain
Proposed for later phases, as originally recorded here.

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
  default for new applications. _(Superseded by ADR-0007: SQLite remains
  the Quick Start default, but is no longer treated as the only supported
  driver or as rigidly coupled to "Personal".)_ Docker is a **single
  container**
  (`Dockerfile` + `docker-compose.yml`) running `php artisan serve`
  directly — no Nginx/PHP-FPM split, no queue worker container, no
  scheduler container. This matches "don't over-size Docker in this
  phase": there is no audit domain yet to justify workers or a scheduler.
- Docker volumes (`laradogs-database`, `laradogs-storage`) persist the
  SQLite file and `storage/` (logs, framework cache, sessions) across
  container recreation, since Personal-profile users are expected to
  `docker compose up`/`down` repeatedly without losing their data.
- ~~**PostgreSQL support for the "Server" profile is deferred**, not
  implemented, not stubbed.~~ _(Superseded by ADR-0007: PostgreSQL — along
  with MySQL and MariaDB — is now an officially supported database for
  LaraDogs' own persistence, independent of deployment profile.)_ Laravel's
  database layer (`config/database.php`) already supports switching
  `DB_CONNECTION` to `pgsql`, `mysql`, or `mariadb` without code changes,
  which is exactly why this was a configuration decision, not an
  abstraction that needed to be built.
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
