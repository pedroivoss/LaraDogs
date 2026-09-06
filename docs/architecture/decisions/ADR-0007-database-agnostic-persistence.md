# ADR-0007: Database-Agnostic Persistence Strategy

## Status

Accepted (Phase 0.1). Supersedes the database-vendor portion of
[ADR-0005](ADR-0005-persistence-and-deployment-profiles.md) — see that
ADR's Status section. ADR-0005's Docker/deployment-profile shape decision
(single container in Phase 0) is unaffected and remains Accepted there.

## Context

ADR-0005 recorded, alongside its Docker-shape decision, a database-vendor
decision: SQLite as _the_ driver for a "Personal" profile, with PostgreSQL
support deferred exclusively to a future "Server" profile. Revisited before
any real audit-domain persistence exists (Phase 3's `Finding`/`Scan`
models), that decision has two problems:

1. **It hardcodes a database vendor into the architecture** rather than
   treating it as ordinary Laravel configuration. Nothing about LaraDogs'
   domain requires SQLite specifically, and `config/database.php` (stock
   Laravel skeleton, unmodified) already ships working connection
   definitions for `sqlite`, `mysql`, `mariadb`, and `pgsql` — switching
   `DB_CONNECTION` is configuration, not code.
2. **It couples database vendor to deployment profile** ("Personal" ⇒
   SQLite, "Server" ⇒ PostgreSQL) when the two are orthogonal concerns. A
   self-hosted single-user deployment may reasonably prefer MySQL (e.g. the
   maintainer's own environment already runs MySQL for other projects); a
   "Server" deployment is not required to run PostgreSQL specifically.

A separate, easily-confused concern: **the database used by LaraDogs
itself is not the same thing as the database used by a project LaraDogs is
auditing.** The Audit Core (Phase 2+) analyzes a target Laravel
application's code, config, and dependencies — it does not need to connect
to, assume, or match that target's database vendor. A LaraDogs instance
running on MySQL must be able to audit Project A (PostgreSQL), Project B
(MySQL), Project C (SQLite), or any other vendor Project Discovery
(Phase 1) detects, without any of that leaking into how LaraDogs persists
its own `Scan`/`Finding` records.

## Decision

- **LaraDogs officially supports four databases for its own persistence:
  SQLite, MySQL, MariaDB, and PostgreSQL.** All four are already wired in
  `config/database.php` via standard Laravel connection definitions
  selected by `DB_CONNECTION`; no vendor-specific code is required to use
  any of them today.
- **SQLite remains the Quick Start default** for zero-configuration local
  use (`DB_CONNECTION=sqlite` in `.env.example`), because it needs no
  separate service to run — not because it's architecturally mandated.
  Choosing MySQL, MariaDB, or PostgreSQL instead is a supported,
  first-class configuration change, not a workaround.
- **Deployment profiles (Personal/Server, once "Server" is built) do not
  dictate database vendor.** A Personal/single-user deployment may use
  MySQL; a Server deployment may use SQLite (with the caveat that SQLite's
  single-writer model is a poor fit for concurrent multi-worker access,
  which is a capacity/operational consideration for whoever deploys it, not
  an architectural restriction LaraDogs enforces). The profile determines
  things like worker/scheduler/reverse-proxy topology — not which
  `DB_CONNECTION` is legal.
- **LaraDogs' own database is independent from any audited project's
  database.** The Audit Core must never assume, require, or read the
  database vendor (or credentials) of a project it audits. Project
  Discovery (Phase 1) may _detect_ what a target project uses (e.g. to
  inform Laravel-aware rules), but that detection has no bearing on, and no
  dependency on, `DB_CONNECTION` for LaraDogs' own instance.
- **Portability constraint for all future LaraDogs-domain persistence
  code** (Phase 3's `Finding`/`Scan` models and every migration after):
    - Use Eloquent and the Query Builder rather than raw SQL.
    - Use Laravel's portable migration column types and index helpers
      (`$table->string()`, `->json()`, `->foreignId()`, etc.) rather than
      vendor-specific DDL.
    - Avoid PostgreSQL-only (e.g. array columns, `jsonb` operators),
      MySQL/MariaDB-only, or SQLite-only (e.g. relying on dynamic typing)
      behavior in domain code.
    - If a genuine need for a vendor-specific feature emerges later (e.g.
      PostgreSQL full-text search, `jsonb` operators for `Finding.metadata`
      queries), that is an explicit, separate, documented architectural
      decision (a new ADR) — not something to reach for by default, and not
      something to solve speculatively now.
    - sqlsrv remains present in `config/database.php` only because it ships
      in Laravel's stock skeleton; it is **not** one of LaraDogs' four
      officially supported databases and is not tested against.

## Consequences

- Phase 3 (`Finding`/`Scan` migrations) must be designed and, at minimum,
  reviewed for compatibility across SQLite, MySQL/MariaDB, and PostgreSQL —
  not authored and tested against SQLite alone. Exact CI coverage (e.g.
  whether all three run in CI, or only linted for portable constructs) is
  a Phase 3 implementation decision, not fixed here.
- Documentation (README, architecture docs, setup/Docker guides) must
  present all four databases as supported, with SQLite clearly labeled as
  the Quick Start default rather than the only option.
- **Current Docker quick-start implementation gap:** the existing
  single-container image (`Dockerfile`) only installs the `pdo_sqlite` PHP
  extension. This ADR makes MySQL/MariaDB/PostgreSQL _architecturally_
  supported immediately; it does **not** claim the Docker image already
  runs them out of the box. Adding `pdo_mysql`/`pdo_pgsql` to the runtime
  image (and, later, optional Compose services for a "Server" profile) is
  low-risk future work, deliberately not performed in this pass to keep
  Phase 0.1 scoped to the architectural/documentation correction — see
  [`docs/development/docker.md`](../../development/docker.md) for what
  works today. Until then, using a non-SQLite database means running
  LaraDogs outside this Docker image (see
  [`docs/development/setup.md`](../../development/setup.md)) against an
  external database instance with the matching PDO extension available.
- `.env.example` gains a comment clarifying that `mysql`/`mariadb`/`pgsql`
  are supported alternatives to the SQLite default, not just inert
  commented-out lines.

## Supported databases

- SQLite (Quick Start default)
- MySQL
- MariaDB
- PostgreSQL

## Portability constraints

- Eloquent / Query Builder over raw SQL for all domain persistence code.
- Portable migration column types and index helpers.
- No vendor-specific SQL/behavior without a dedicated ADR justifying it.
- Audit Core must remain agnostic to the audited project's database vendor
  at all times — Project Discovery may detect it; nothing downstream may
  depend on it matching (or even existing in a form) LaraDogs can connect
  to.
