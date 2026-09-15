# Self-Hosting LaraDogs (Phase 7.1.1 / 7.1.2 / 7.1.3 / 7.1.4)

This is the complete guide to running LaraDogs as a real, self-hosted
Docker deployment: the environment, the project-mount model, the
authorization/user model, and the day-to-day audit workflow. For what
the Docker image itself does (stages, extensions, healthcheck), see
[`development/docker.md`](development/docker.md). For the Dashboard's own
architecture, see [`../docs/dashboard.md`](dashboard.md).

## Quick Start

```bash
git clone <this-repo> laradogs && cd laradogs
cp .env.example .env
php artisan key:generate --show   # copy the output into APP_KEY in .env

# Optional: point LARADOGS_PROJECTS_PATH in .env at a real directory of
# Laravel projects (defaults to ./projects, empty, zero-config).

docker compose up -d --build
docker compose exec app php artisan laradogs:user:create-owner
```

Then:

1. Open `http://localhost:17347`.
2. Log in with the credentials you just created.
3. Go to **Projects → Add Project**, pick a directory, register it.
4. On the Project Detail page, click **Run Audit**.
5. Watch it go Queued → Running → Completed (the page polls itself; no
   refresh needed) and browse the results.

No terminal is needed for a normal manual audit after registration — the
`docker compose exec app php artisan laradogs:project:audit <PUBLIC_ID>`
command shown on Project Detail remains available for
debugging/scripted use (see [Audit workflow](#audit-workflow) below).

Migrations run automatically on every container start (see
`docker/entrypoint.sh`) — no separate migration step is needed. `docker
compose up -d --build` above brings up all four services (`app`, `db`,
`worker`, `scheduler`) — see [Docker services](#docker-services) below.

## Canonical Docker execution model

**Run every LaraDogs Artisan command through the container, never
directly on the host:**

```bash
docker compose exec app php artisan ...
```

**Not** `php artisan ...` on the host. The reason is `DB_HOST=db` —
that's a Docker-network service name, resolvable only from inside the
Compose network. Running `php artisan` directly on the host fails with:

```
SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db failed
```

This isn't a bug — it's expected for the documented Docker profile. Every
example in this guide and in the Dashboard's own UI (the "Run a new
audit" command shown on Project Detail) uses the `docker compose exec
app` form for exactly this reason.

### Optional: host-native development

A developer running LaraDogs natively (no Docker, contributing to
LaraDogs itself) is a **different, separate execution profile** — see
[`development/setup.md`](development/setup.md). There, `DB_HOST` would be
`127.0.0.1` with the MySQL container's published host port
(`APP_DATABASE_PORT`, default `17348`) or SQLite, never `db`. Don't mix
the two: a host-native `.env` and a Docker `.env` need different `DB_*`
values, and this guide's `docker compose exec app` commands assume the
Docker profile throughout.

## Ports

|                  | Host (configurable)                   | Container (fixed) |
| ---------------- | ------------------------------------- | ----------------- |
| App              | `APP_PORT` (default `17347`)          | `8000`            |
| MySQL (optional) | `APP_DATABASE_PORT` (default `17348`) | `3306`            |

`APP_URL` should match `APP_PORT` (`http://localhost:17347` by default).
LaraDogs itself always connects to MySQL via `DB_HOST=db`/`DB_PORT=3306`
— the Docker-network address — regardless of what `APP_DATABASE_PORT` is
set to; that variable only controls whether/where MySQL is reachable
from the **host** (e.g. for a local GUI client). See
[`development/docker.md`](development/docker.md#the-db-service) for the
full `db` service (image, healthcheck, isolation, volume).

## Docker services

Four services: `app` (the Dashboard/CLI), `db` (MySQL), and — new in
Phase 7.1.4 — `worker` (consumes queued audits) and `scheduler` (ticks
every minute, dispatches due scheduled audits). Neither `worker` nor
`scheduler` publishes a host port; only `worker` mounts `/projects`
(read-only, same as `app`) — `scheduler` never touches project
filesystems, it only enqueues. Full detail (timeouts, healthcheck
behavior, retry policy) in
[`development/docker.md`](development/docker.md#worker--scheduler-services-phase-714)
and [`auditing/audit-execution.md`](auditing/audit-execution.md).

## Project-root mounting

LaraDogs audits directories under a single configured project root,
mounted **read-only**:

```
HOST: ${LARADOGS_PROJECTS_PATH}   (default ./projects)
    ↓
CONTAINER: /projects   (fixed, :ro)
```

Set `LARADOGS_PROJECTS_PATH` in your own `.env` (never commit a real
value beyond the `./projects` default/example in `.env.example`) to a
real directory of Laravel projects, then recreate the container:

```bash
docker compose down
docker compose up -d
```

(Not `docker compose down -v` — see [Persistence](#persistence) below.)

Verify the mount:

```bash
docker compose exec app ls /projects
docker compose exec app test -d /projects/your-project && echo "mounted"
docker compose exec app php artisan laradogs:inspect /projects/your-project
```

### Project-root security

The Dashboard's "Add Project" picker is **not** a filesystem browser. It
only ever lists the direct children of the configured root
(`App\Audit\Projects\ProjectDirectoryDiscovery`) and only ever accepts a
directory NAME from that list back from the browser — never a typed
path. The backend resolves that name to an absolute path with
`realpath()` and verifies it still falls within the root before it's
ever handed to `RegisterProject`. This is what rejects:

- absolute paths (`/etc`, `/root`, `/home`, `/app`);
- traversal (`../../etc`);
- a symlink planted under `/projects` that points outside it.

All covered by `tests/Unit/Audit/Projects/ProjectDirectoryDiscoveryTest.php`
and `tests/Feature/Projects/ProjectRegistrationControllerTest.php`.

## Authorization model

LaraDogs has three roles, via a single `users.role` column (`owner` /
`admin` / `user` — a portable string, never a database-vendor `ENUM`
type; see `App\Models\Role`) — no RBAC package, no teams/organizations:

| Capability                 | Owner | Admin | User |
| -------------------------- | ----- | ----- | ---- |
| Dashboard / view projects  | yes   | yes   | yes  |
| Register project           | yes   | yes   | no   |
| Run manual audit           | yes   | yes   | no   |
| Manage audit schedule      | yes   | yes   | no   |
| View audit schedule/status | yes   | yes   | yes  |
| Create/manage normal Users | yes   | yes   | no   |
| Manage Admins              | yes   | no    | no   |
| Create Admin               | yes   | no    | no   |
| Promote/demote             | yes   | no    | no   |
| Manage Owner               | self  | no    | no   |
| Deactivate Owner           | no    | no    | no   |
| Own profile/password       | yes   | yes   | yes  |

**Project registration is Owner/Admin-only.** It grants access to
server-mounted filesystem paths under `/projects`, which this V1
self-hosted model treats as a staff capability, not a general user one.
**Triggering/scheduling an audit is the same staff-only capability**
(Phase 7.1.4) — an audit consumes real server CPU/network, an
administrative operation in this model, not a general user one; see
[`auditing/audit-execution.md`](auditing/audit-execution.md).

**The Owner is never a management target** — not by an Admin, not even
by themselves. The Owner's own profile/password go through the same
Settings → Profile/Security pages every role uses; Settings → Users
simply excludes the Owner from every response for a non-Owner actor
(server-side, not merely hidden in the UI — see
[Owner privacy](#owner-privacy) below), so there is nothing there to
even attempt to manage. Exactly **one** active Owner exists at a time,
enforced at the application layer (`App\Policies\UserPolicy` — see that
class's own docblock) rather than a database constraint, since the only
two code paths that can ever assign the Owner role
(`laradogs:user:create-owner`, `laradogs:user:claim-owner`) both refuse
outright if one already exists and are CLI-only, never reachable over
HTTP.

## Owner privacy

An Admin's Settings → Users never contains the Owner, and never contains
other Admin accounts either (Admin manages Users only) — the query
itself excludes them (`UsersController::index()`), so there is no row to
hide client-side. Requesting the Owner's (or another Admin's, as an
Admin) edit/update/password/activate endpoint directly by id behaves
identically to requesting an id that doesn't exist: `404`, matching this
app's existing IDOR-guard convention elsewhere (never `403`, which would
at least confirm the id exists). See
`tests/Feature/Settings/UsersControllerTest.php`'s privacy-focused tests
for the full list of endpoints this covers.

## Instance Owner bootstrap

There is no default Owner account, ever — LaraDogs never ships or
auto-creates a known credential (no `admin`/`admin`, no
`owner@laradogs.test`/`password`). The first account is always
provisioned explicitly:

```bash
docker compose exec app php artisan laradogs:user:create-owner
```

Prompts for name, email, and password (hidden input). Refuses outright
if an Owner already exists. Until this has been run once, the landing
and login pages show a generic "An Instance Owner has not been
configured yet" message (plus, on the landing page only, the exact
command above) — never environment/configuration details.

For scripted/non-interactive first-boot automation only, the command
also accepts `LARADOGS_ADMIN_NAME`/`LARADOGS_ADMIN_EMAIL`/
`LARADOGS_ADMIN_PASSWORD` from the environment (see `.env.example`) — if
unset, the default, it always prompts interactively instead. The
variable names keep their Phase 7.1.2 `ADMIN` naming (an existing `.env`
value keeps working); what they bootstrap is now the Owner.

### `laradogs:user:create-admin`

Provisions an **Admin** account, and now **requires an Owner to already
exist** — a deliberate, documented semantics change from Phase 7.1.2
(where this command created the very first privileged account). Running
it before an Owner exists fails cleanly with a message pointing at
`create-owner`. An Owner can equally create an Admin from the
Dashboard's Settings → Users; this command exists for CLI-only/scripted
provisioning.

### `laradogs:user:claim-owner {email}`

Promotes an **existing** account to Owner — never creates a new one.
Exists for exactly one scenario: upgrading from a Phase 7.1.2
installation that had more than one admin account (see
[Upgrading](#upgrading-from-phase-712-isadmin) below). Also refuses if
an Owner already exists.

## User management

An Owner or Admin manages other accounts under **Settings → Users**:

- list users (Owner sees Admins + Users; Admin sees Users only — see
  [Owner privacy](#owner-privacy));
- create a user — Owner may choose Admin or User as the new account's
  role; Admin's choice is always forced to User server-side regardless
  of what the form submits;
- edit a user's name/email;
- set a user's password directly (no "current password" needed — the
  actor never sees or needs the existing one; it stays hashed and
  unreadable either way);
- activate/deactivate — revokes/restores access without deleting the
  account or its history; never on yourself, never on the Owner;
- promote (User → Admin) / demote (Admin → User) — **Owner only**. An
  Admin cannot change anyone's role, including their own.

## Upgrading from Phase 7.1.2 (`is_admin`)

The upgrade migration
(`2026_09_11_000001_replace_is_admin_with_role_on_users_table`) runs
automatically on the next container start, like every other migration —
no manual step needed. It's deterministic and requires no interactive
input:

- every `is_admin = false` account → `role = user`;
- **exactly one** `is_admin = true` account → `role = owner` (safe:
  there's no other candidate to confuse it with);
- **more than one** `is_admin = true` account → `role = admin` for ALL
  of them, deliberately **not** auto-selecting one as Owner. Silently
  picking one by row order could hand Owner-only capabilities to
  whichever account happened to be created first, not necessarily who
  the operator would choose.

If your installation lands in that last case (check with **Settings →
Users** — an Owner-less installation won't show one there because Owner
doesn't exist yet), run once:

```bash
docker compose exec app php artisan laradogs:user:claim-owner your-email@example.com
```

No installation loses administrative access on upgrade: every prior
admin becomes at least Admin, never silently demoted to User.

## Personal account settings

Every authenticated user manages their own name, email, and password
under **Settings → Profile** / **Settings → Security** — the existing
starter-kit settings pages, unchanged by this phase (current-password
validation, confirmation, unique-email validation all already applied).

## Public registration

Disabled at the framework level, not merely hidden from the UI:
`config/fortify.php`'s `features` list no longer includes
`Features::registration()`, so Fortify's own route provider never
registers `GET`/`POST /register` at all — both return `404`, not a
redirect or a validation error. See
`tests/Feature/Auth/RegistrationDisabledTest.php`.

## Project registration UI (CLI fallback)

The Dashboard's **Projects → Add Project** is the normal path. The CLI
remains fully supported (and is the only path for non-admin automation,
scripting, or environments without a browser):

```bash
docker compose exec app php artisan laradogs:inspect /projects/your-project

docker compose exec app php artisan laradogs:project:add \
  /projects/your-project \
  --name="Your Project"

docker compose exec app php artisan laradogs:project:list

docker compose exec app php artisan laradogs:project:audit <PUBLIC_ID>
```

Registration is idempotent either way (UI or CLI) — registering the same
resolved path twice never creates a duplicate row; see
[`auditing/projects.md`](auditing/projects.md).

## Audit workflow

Click **Run Audit** on Project Detail (Owner/Admin only — see
[Authorization model](#authorization-model) above; a User sees the same
card read-only). It queues instantly and a worker picks it up — no
terminal needed. Project Detail also still shows the exact,
copy/paste-ready CLI command, which remains fully supported and runs
**synchronously** (useful for debugging/scripted automation):

```bash
docker compose exec app php artisan laradogs:project:audit <PUBLIC_ID>
```

Optionally enable an **automatic schedule** (Disabled by default,
Daily/Weekly/Monthly) from the same page — Owner/Admin to change,
visible to every role. Full design (concurrency, worker failure
handling, scheduling semantics) in
[`auditing/audit-execution.md`](auditing/audit-execution.md).

## Persistence

```bash
docker compose down       # preserves both named volumes (storage/MySQL)
docker compose down -v    # DESTROYS them — only for a full local reset
```

Queued/scheduled audits survive `docker compose restart` (or a `worker`
container crash and restart) — the queue itself is backed by MySQL
(`QUEUE_CONNECTION=database`), not held in the worker process's memory.
A scan that was genuinely `Running` when its worker died is recovered
automatically the next time an audit is requested for that project — see
[`auditing/audit-execution.md`](auditing/audit-execution.md#stale-scan-recovery-two-separate-thresholds).

## Troubleshooting

**"port is already allocated"** — another process/Compose stack already
publishes that host port. Set a different value in `.env` (`APP_PORT=...`
and/or `APP_DATABASE_PORT=...`), no source change needed, then
`docker compose up -d`.

**Container stuck `Restarting`** — inspect first, don't guess:

```bash
docker compose ps -a
docker compose logs app
```

The most common cause is `DB_*` values in `.env` not matching a database
the container can actually reach.

**`DB_HOST=db` fails when run on the host** — expected; Docker's internal
DNS only resolves inside the Compose network. Use
`docker compose exec app ...` — see
[Canonical Docker execution model](#canonical-docker-execution-model).

**"Path not found" registering a project** — a host filesystem path isn't
automatically visible inside the container. Set `LARADOGS_PROJECTS_PATH`
in `.env` to the directory containing the project, recreate the
container, then use the container path (`/projects/<name>`), never the
host path.

**Empty "Add Project" picker** — check `LARADOGS_PROJECTS_PATH` is set
and points at a real directory containing project subdirectories, then
`docker compose down && docker compose up -d`.

**"Run Audit" shows "Queued" forever** — check the `worker` service is
actually running: `docker compose ps worker`. If it's not, `docker
compose up -d worker` (or `docker compose up -d` to bring up everything);
a queued-but-unpicked-up scan is also automatically reclaimed as `Failed`
after `queued_scan_stale_threshold_seconds` (120s default) the next time
an audit is requested for that project — see
[`auditing/audit-execution.md`](auditing/audit-execution.md).

**Automatic schedule never seems to fire** — check the `scheduler`
service is running: `docker compose ps scheduler`. `docker compose logs
scheduler` shows each minute's tick; a project only fires once its
`next_audit_at` (shown on Project Detail) is actually in the past.

## Known limitations

- **Ownership transfer is deferred.** There is no UI/command to move
  Owner from one account to another once assigned (only the two
  bootstrap-time paths above ever assign it, and both refuse once an
  Owner exists). If this is ever needed, it's future work.
- No delete action exists for any account (Owner, Admin, or User) —
  deactivation is the only access-revocation mechanism this phase
  ships, deliberately (see [User management](#user-management) above).

## Security summary

- No hardcoded/default Owner/Admin credential, ever.
- Public self-registration disabled at the route level (`404`, not hidden UI).
- Passwords always hashed (`bcrypt`, Laravel's `hashed` cast) — never
  displayed, never retrievable, only settable.
- Project registration is Owner/Admin-only.
- The Owner is invisible to Admin/User through every Settings → Users
  response (server-side query exclusion, not UI hiding) and unreachable
  through any of its endpoints (`404`, matching this app's IDOR-guard
  convention).
- `role`/`is_active` are excluded from `User`'s mass-assignable
  (`#[Fillable]`) attributes — a request body can never smuggle a role/
  activation change through an unrelated form; every legitimate change
  goes through `App\Policies\UserPolicy`-gated code that sets them via
  direct, explicit property assignment.
- Deactivated accounts can neither log in nor keep an already-
  authenticated session past the next request
  (`App\Http\Middleware\EnsureUserIsActive`) — indistinguishable from a
  wrong password to an unauthenticated caller.
- Deactivating/removing access from an account never destroys its
  historical finding-lifecycle references — `actor_identifier` is
  always a plain string snapshot (the email at transition time), never
  a foreign key to `users`.
- Filesystem access is scoped to `/projects` and its direct children,
  read-only, with realpath containment against traversal and symlink
  escapes.
- No Docker socket is mounted; nothing auto-mounts arbitrary host paths.
- Registered project paths are always the container-canonical path
  (`/projects/<name>`) — a host path is never persisted or displayed.
- Triggering/scheduling an audit is Owner/Admin-only, enforced server-side
  (route middleware + policy), never merely hidden in the UI. A project
  can never have two active scans at once — enforced by a real database
  primary-key constraint (`project_active_scans`), not an advisory
  check — so a double-click or a manual+scheduled race can never dispatch
  two concurrent audits. Queued jobs carry only a stable scan id, never a
  filesystem tree or analyzer instance. The `scheduler` service never
  mounts `/projects` and never runs an analyzer — it only enqueues. See
  [`auditing/audit-execution.md`](auditing/audit-execution.md) for the
  full design.
