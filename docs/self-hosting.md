# Self-Hosting LaraDogs (Phase 7.1.1 / 7.1.2)

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
docker compose exec app php artisan laradogs:user:create-admin
```

Then:

1. Open `http://localhost:17347`.
2. Log in with the administrator you just created.
3. Go to **Projects → Add Project**, pick a directory, register it.
4. Copy its public ID from the Project Detail page.
5. Run `docker compose exec app php artisan laradogs:project:audit <PUBLIC_ID>`.
6. Refresh the Dashboard and browse the results.

Migrations run automatically on every container start (see
`docker/entrypoint.sh`) — no separate migration step is needed.

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

LaraDogs has two roles, via a single `users.is_admin` boolean — no
RBAC package, no teams/organizations:

|                         | Guest | User | Administrator |
| ----------------------- | ----- | ---- | ------------- |
| Dashboard               | no    | yes  | yes           |
| Register/audit projects | no    | no   | yes           |
| User management         | no    | no   | yes           |
| Own profile/password    | —     | yes  | yes           |

**Project registration is admin-only.** It grants access to
server-mounted filesystem paths under `/projects`, which this V1
self-hosted model treats as an administrative capability, not a general
user one. (Running an already-registered project's audit is currently
CLI-only regardless of role — see
[`dashboard.md`](dashboard.md#audit-trigger-design-cli-only-this-phase) —
so this restriction is specifically about the registration step.)

`is_admin` is set once, at account creation, and is **immutable from the
UI this phase** (no promote/demote, no activate/deactivate) — a
deliberate, smaller-and-safer scope than half-implementing role changes
with "last admin" lockout protection this phase doesn't need yet. The
first administrator is always created by `laradogs:user:create-admin`;
every user an administrator creates afterward is always a regular user.

## Initial administrator

There is no default administrator account, ever — LaraDogs never ships
or auto-creates a known credential (no `admin`/`admin`, no
`admin@laradogs.test`/`password`). The first account is always
provisioned explicitly:

```bash
docker compose exec app php artisan laradogs:user:create-admin
```

Prompts for name, email, and password (hidden input). Until this has
been run once, the login page shows a generic "An administrator account
has not been configured yet" message — never environment/configuration
details.

For scripted/non-interactive first-boot automation only, the command
also accepts `LARADOGS_ADMIN_NAME`/`LARADOGS_ADMIN_EMAIL`/
`LARADOGS_ADMIN_PASSWORD` from the environment (see `.env.example`) — if
unset, the default, it always prompts interactively instead. Running the
command again with an email that already exists fails cleanly (no
duplicate, no silent overwrite).

## User management

An administrator manages other accounts under **Settings → Users**
(only visible to administrators):

- list users;
- create a user (name, email, password);
- edit a user's name/email;
- set a user's password directly (no "current password" needed — the
  admin never sees or needs the existing one; it stays hashed and
  unreadable either way).

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

Dashboard-triggered audits remain deliberately out of scope (long-running
Semgrep + no queue worker process yet — see
[`dashboard.md`](dashboard.md#audit-trigger-design-cli-only-this-phase)).
Project Detail shows the exact, copy/paste-ready Docker command:

```bash
docker compose exec app php artisan laradogs:project:audit <PUBLIC_ID>
```

## Persistence

```bash
docker compose down       # preserves all 3 named volumes (database/storage/MySQL)
docker compose down -v    # DESTROYS them — only for a full local reset
```

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

## Security summary

- No hardcoded/default administrator credential, ever.
- Public self-registration disabled at the route level (`404`, not hidden UI).
- Passwords always hashed (`bcrypt`, Laravel's `hashed` cast) — never
  displayed, never retrievable, only settable.
- Project registration is admin-only.
- Filesystem access is scoped to `/projects` and its direct children,
  read-only, with realpath containment against traversal and symlink
  escapes.
- No Docker socket is mounted; nothing auto-mounts arbitrary host paths.
- Registered project paths are always the container-canonical path
  (`/projects/<name>`) — a host path is never persisted or displayed.
