# Docker (Personal Profile)

The "Personal" profile (single-node local/self-hosted use, described in
[ADR-0005](../architecture/decisions/ADR-0005-persistence-and-deployment-profiles.md))
runs two containers: the LaraDogs `app` and a dedicated, isolated MySQL
`db` service (Phase 7.1.1). MySQL is this Compose profile's own choice for
a realistic, self-contained UAT environment — not an architectural
requirement. LaraDogs itself officially supports SQLite, MySQL, MariaDB,
and PostgreSQL equally; see
[ADR-0007](../architecture/decisions/ADR-0007-database-agnostic-persistence.md)
and [Database support](#database-support) below. There is no "Server"
profile (Redis, workers, scheduler, reverse proxy) yet — see the roadmap.

## Start

```bash
cp .env.example .env
php artisan key:generate --show   # copy the output into APP_KEY in .env
docker compose up --build
```

Visit `http://localhost:17347`. Both host ports are configurable in
`.env` without touching source code:

- `APP_PORT` (default `17347`) — the Dashboard's host port. The container
  always listens on `:8000` internally regardless.
- `APP_DATABASE_PORT` (default `17348`) — **optional** host publication of
  the `db` container's MySQL, e.g. for a local GUI client. LaraDogs itself
  never uses this port; it always connects over the Docker network via
  `DB_HOST=db`/`DB_PORT=3306`, which never change.

See [Troubleshooting](#troubleshooting) below if either default is
already taken.

Configuration — including `APP_KEY` — is supplied entirely via
`docker-compose.yml`'s `env_file: .env`, i.e. real process environment
variables inside the container. There is no `.env` file inside the image
or container itself, so `APP_KEY` must already be set in the host's `.env`
before starting; the entrypoint fails fast with a clear error if it isn't
(a blank key would otherwise silently break session/cookie encryption).

The container's entrypoint (`docker/entrypoint.sh`) is idempotent: on every
start it runs `php artisan migrate --force` (and, only when
`DB_CONNECTION=sqlite`, creates `database/database.sqlite` if missing).
It's safe to run repeatedly. `app` waits for `db`'s healthcheck to pass
before starting (`depends_on: condition: service_healthy`), so it never
races MySQL initialization.

## Database support

The `runtime` stage installs both `pdo_sqlite`/`sqlite3` and `pdo_mysql`
(Phase 7.1.1). The Docker Compose profile's own default is MySQL, via the
dedicated `db` service below — isolated by its own network, named volume
(`laradogs-mysql-data`), database, and credentials from **this** `.env`;
it never connects to an already-running host/other-project MySQL. SQLite
remains fully supported (see the commented block in `.env.example`) if you
prefer the lighter single-database-file profile. `pdo_pgsql` is not
installed in this image; using PostgreSQL means running LaraDogs outside
Docker (see [`setup.md`](setup.md)) against an external instance.

### The `db` service

- **Image**: `mysql:8.4` (pinned, never `latest`).
- **Isolation**: its own Compose network (`laradogs`), its own named
  volume (`laradogs-mysql-data`, never a host directory or a volume
  shared with another project), its own database/user, all sourced from
  this project's `.env` (`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`
  become `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD`).
- **No root application account**: LaraDogs connects as the
  `DB_USERNAME` user. `DB_ROOT_PASSWORD` (→ `MYSQL_ROOT_PASSWORD`) exists
  only for the MySQL container's own first-boot initialization.
- **Healthcheck**: `mysqladmin ping`, so `app` never races startup —
  Compose's `condition: service_healthy` blocks `app` from starting
  until `db` reports healthy.
- **Host port** (`APP_DATABASE_PORT`, default `17348`) is optional and
  separate from the internal `DB_PORT=3306` LaraDogs itself always uses
  — see [Start](#start) above.

## Project mount (auditing local projects)

`docker-compose.yml` never hardcodes a personal path — the `app` service
mounts `${LARADOGS_PROJECTS_PATH:-./projects}:/projects:ro`, a HOST env
var (set in your own gitignored `.env`, defaulting to an empty
`./projects` directory so a fresh checkout works with zero
configuration), always **read-only** — see
[`composer-audit.md`](../auditing/analyzers/composer-audit.md#docker-impact)
for why analyzers never need write access to an audited project. Full
guide (project-root security, the Dashboard's "Add Project" picker, the
CLI fallback, first-administrator bootstrap, user management) in
[`../self-hosting.md`](../self-hosting.md).

## Troubleshooting

**`Bind for 0.0.0.0:PORT failed: port is already allocated`** — another
process (often another project's Docker Compose stack) already publishes
that host port. Set a different value in `.env` (`APP_PORT=...` and/or
`APP_DATABASE_PORT=...`) — no source change needed — then
`docker compose up -d` again.

**`app` stuck `Restarting (1)`** — inspect the real cause before changing
anything:

```bash
docker compose logs app --tail=50
```

The most common cause is `DB_CONNECTION`/`DB_HOST`/`DB_PORT` in `.env` not
matching a database the container can actually reach (e.g.
`DB_CONNECTION=mysql` without `DB_HOST=db` — the entrypoint's
`php artisan migrate --force` then fails, and `restart: unless-stopped`
loops forever). Fix the `.env` values (see the MySQL block documented in
`.env.example`) rather than changing the restart policy — that would hide
the crash, not fix it.

**Running `php artisan` directly on the host fails with `getaddrinfo for
db failed`** — expected; `DB_HOST=db` only resolves inside the Compose
network. Use `docker compose exec app php artisan ...` instead — see
[`../self-hosting.md`](../self-hosting.md#canonical-docker-execution-model).

**Project path not found / empty "Add Project" picker** — see
[`../self-hosting.md`](../self-hosting.md#troubleshooting)'s project-mount
troubleshooting.

## Stop

```bash
docker compose down
```

Data persists in two named Docker volumes (`laradogs-storage`,
`laradogs-mysql-data`) across `up`/`down` cycles — deliberately NOT a
third volume over `/app/database` (see docker-compose.yml's own comment):
that path also holds `database/migrations/`, application code that must
always come from the built image, never be frozen at whatever a volume
first saw. To fully reset local data (**destroys the database**):

```bash
docker compose down -v
```

## What the image does

Three-stage build (`Dockerfile`):

1. **`composer_bin`** — a named alias for the official, pinned
   `composer:${COMPOSER_VERSION}` image (see
   [Composer in the runtime image](#composer-in-the-runtime-image) below)
   — exists only so later stages can `COPY --from=composer_bin` the
   `composer` binary; nothing else from this stage is ever used.
2. **`builder`** — `php:8.3-cli-bookworm` + Node 22, installs Composer
   (from `composer_bin`) and npm dependencies, builds the Vite bundle
   (this is also where Wayfinder generates typed route helpers, which
   requires the full application code to introspect routes/controllers),
   then dumps an optimized classmap-authoritative autoloader. Discarded
   after build (except for the two files `runtime` explicitly copies out
   of it — see below).
3. **`runtime`** — a fresh `php:8.3-cli-bookworm` with `pdo_sqlite`,
   `sqlite3`, `curl` (for the healthcheck), the real `composer` binary
   (Phase 4.1), a real Node.js + npm installation (Phase 4.2), and (Phase 5) a real Semgrep CLI installed into an isolated Python virtualenv.
   Runs as a non-root user (`laradogs`, uid 1000), not root. No dev
   dependencies, no LaraDogs-frontend build toolchain ship in this layer —
   see [Node/npm in the runtime image](#nodenpm-in-the-runtime-image) and
   [Semgrep in the runtime image](#semgrep-in-the-runtime-image) below for
   why each is installed the way it is, rather than copied from `builder`
   the way Composer's binary is.

A `HEALTHCHECK` hits the app's built-in `/up` route (registered via
Laravel's `health:` routing option in `bootstrap/app.php`, not custom
code).

## Composer in the runtime image

**Status: Implemented (Phase 4.1).** Phase 4 shipped `ComposerAuditAnalyzer`
but left the `composer` binary out of the `runtime` stage entirely — a
known, documented gap at the time. Phase 4.1 closes it:

- **Pinned, reproducible version, one source of truth.** A single
  `ARG COMPOSER_VERSION=2.10.3` (declared once, at the top of the
  Dockerfile) replaces the previous floating `composer:2` reference. It
  feeds a named stage, `composer_bin` (`FROM composer:${COMPOSER_VERSION}
AS composer_bin`) — a separate stage, not a bare
  `COPY --from=composer:${COMPOSER_VERSION}`, because BuildKit does not
  support variable expansion directly in `--from`; this is the documented
  workaround, and it also means the pinned image is fetched exactly once
  regardless of how many later stages need a file from it. `2.10.3` is
  pinned comfortably above
  `App\Audit\Analyzers\Composer\ComposerAuditAnalyzer::MIN_SUPPORTED_VERSION`
  (`2.4.0`, the one and only place that floor is defined) — a Dockerfile
  comment cross-references that constant so the two never drift apart
  silently. A floating tag (`composer:2`/`composer:latest`) was
  deliberately rejected: a rebuild months from now would then silently
  ship whatever Composer happened to be current at that moment, not the
  version this image was actually verified against.
- **The `runtime` stage reuses the exact same binary the `builder` stage
  already has**, via `COPY --from=builder /usr/bin/composer /usr/bin/composer`
  — not a second `COPY --from=composer_bin` — so the pinned image is only
  ever pulled once, and `builder`/`runtime` are guaranteed to carry
  byte-identical Composer binaries. This is "reuse an artifact that
  already exists," not "copy the builder's entire toolchain": exactly one
  file crosses the stage boundary.
- **No `zip` extension, no `git`, no `unzip` needed in `runtime`.**
  Verified by actually running `composer audit` inside a built runtime
  container: `composer audit --locked` only needs an HTTPS call to
  Packagist's advisory API and JSON parsing — `curl`/`openssl`/`phar`/
  `json`/`mbstring` are already compiled into the base `php:8.3-cli-bookworm`
  image with no extra `docker-php-ext-install`. Those extensions are only
  needed for `composer install`/`update` (extracting zip-distributed
  packages), which this image's runtime never runs.
- **`COMPOSER_HOME` is set explicitly**, as a plain Dockerfile `ENV`
  (`ENV COMPOSER_HOME=/home/laradogs/.composer`), created and
  `chown`'d to the `laradogs` user at build time. This reaches the
  `composer` subprocess with **zero analyzer code changes**: `COMPOSER_HOME`
  was already part of `config('laradogs.process.env_allowlist')` since
  Phase 4, so `ComposerAuditAnalyzer`'s existing allowlist-building logic
  picks it up automatically from LaraDogs' own (container-level)
  environment. Setting it explicitly — rather than relying on Composer's
  own `$HOME`-derived default resolution, which has changed between
  versions (legacy `~/.composer` vs. newer XDG-based paths) — means this
  stays true regardless of future Composer version bumps. Verified inside
  a running container: `composer audit`'s cache lands at exactly
  `/home/laradogs/.composer/cache/...` — never inside `/app` (LaraDogs'
  own code) and never inside a mounted target directory.
- **Verified against a real, read-only-mounted target.** A fixture
  directory was bind-mounted into a running container with Docker's `:ro`
  flag; `composer audit --locked` against it succeeded (real advisories
  returned, matching every other run of the same fixture in this
  project), and an explicit `touch` inside the mount failed with
  "Read-only file system" — proving the analyzer genuinely never needs
  write access to a target project, not merely that it happens not to
  write today. See
  [`../auditing/analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md#docker-impact).
- **Verified end-to-end via a real `docker compose build`, a real running
  container (non-root, `healthy`), `composer --version` (`2.10.3`) inside
  it, and a real `php artisan laradogs:audit` run** against the
  read-only-mounted fixture, returning `AVAILABLE`/`Passed` with real
  advisories. All test containers, volumes, and temp directories created
  for this verification were removed afterward; nothing was left running.

## Node/npm in the runtime image

**Status: Implemented (Phase 4.2).** Unlike Composer's single-file PHAR
(reused via one `COPY --from=builder`), npm is not one file —
`/usr/bin/npm` is a thin wrapper around a full `/usr/lib/node_modules/npm/`
tree, so "copy just the binary" doesn't work the same way for it. Node/npm
are instead **installed directly in `runtime`**, reusing the exact same
`NODE_VERSION=22` build ARG the `builder` stage already used for its own
frontend build (not a new, separate version knob) via the identical
NodeSource setup-script + apt mechanism already used there. `gnupg` is
only needed transiently for that repo-setup step and is purged in the
same Docker layer once `nodejs` itself is installed, so it never appears
in the final image. This is the same level of version-pin precision this
Dockerfile already uses for PHP (`PHP_VERSION=8.3` — a pinned major/minor
line, not an exact patch) — not `latest`.

Verified with a real `docker compose build` + a running (non-root,
healthy) container:

- `node --version` → `v22.23.2`; `npm --version` → `10.9.8` — both above
  `App\Audit\Analyzers\Npm\NpmAuditAnalyzer::MIN_SUPPORTED_VERSION`'s
  `7.0.0` floor.
- `composer --version` → `2.10.3` — confirmed **no Composer regression**
  from adding Node/npm to the same `runtime` stage.
- `php artisan laradogs:audit <fixture> --json --analyzer=npm-audit`
  against a **read-only-mounted** (`:ro`) fixture (with both a Composer
  and an npm dimension) returned `AVAILABLE`/`Passed` with real
  advisories; an explicit `touch` inside the mount failed with "Read-only
  file system." `--analyzer=composer-audit` against the same fixture
  still works; omitting `--analyzer` entirely correctly ran and reported
  **both** analyzers as `passed` in one invocation.
- npm's cache was confirmed to land at
  `/app/storage/app/laradogs/npm-cache/...` — auto-created on demand, with
  **zero Dockerfile changes needed** for this (unlike Composer's
  `COMPOSER_HOME`, which the image sets as a container `ENV`, npm's
  `NPM_CONFIG_USERCONFIG`/`NPM_CONFIG_CACHE` are always set by the
  analyzer itself from `config('laradogs.npm.*')`, defaulting to paths
  under LaraDogs' own `storage_path()` — see
  [`../auditing/analyzers/npm-audit.md`](../auditing/analyzers/npm-audit.md#11-cachehometemp)).
  Never inside `/app`'s own tracked code, never inside the target.
- **Image size impact: approximately +229MB** (569MB → 798MB) for a full
  Node.js + npm runtime — not micro-optimized this phase, but the
  LaraDogs frontend's own `node_modules`, the `builder` stage's npm
  cache, and Node source/build dependencies were deliberately NOT copied
  into `runtime`; only the apt `nodejs` package itself was added.
- All test containers, volumes, and temp directories created for this
  verification were removed afterward; nothing was left running.

**Phase 4.2.1 (registry/proxy trust hardening) required zero Dockerfile
changes.** The new `--proxy=false --https-proxy=false --strict-ssl=true`
flags and the `NpmConfigInspector` fail-closed check are pure application
code/config, identical in Docker and locally. Re-verified with a real
`docker compose build` + a running container: a hostile `.npmrc`
(scoped-registry override, proxy pointed at an always-closed port) mounted
`:ro` still produced a correct, real `Passed` audit result inside the
image, with Composer unaffected.

## Semgrep in the runtime image

**Status: Implemented (Phase 5).** Semgrep's own official Docker image
(`semgrep/semgrep`) was inspected directly before choosing a strategy
(`docker run --rm --entrypoint sh semgrep/semgrep:1.176.0 -c 'cat
/usr/bin/semgrep; readlink -f /usr/bin/semgrep'`): it is
Alpine/musl-based, and `/usr/bin/semgrep` is a thin
`#!/usr/bin/python3` script backed by a full Python `site-packages` tree —
not a standalone binary the way Composer's PHAR is, and not portable to
this image's glibc/Debian `bookworm` base by copying just the file (unlike
Composer's `COPY --from=composer_bin` strategy).

Instead, `runtime` installs `python3`/`python3-venv` via apt, creates an
isolated virtualenv at `/opt/semgrep-venv`, and `pip install`s Semgrep
pinned to a new `SEMGREP_VERSION` build ARG (default `1.176.0`, matching
`App\Audit\Analyzers\Semgrep\SemgrepAnalyzer::MIN_SUPPORTED_VERSION`) —
never `latest`, never an unpinned `pip install semgrep`. The venv is
entirely separate from both the system Python and LaraDogs' own PHP/Node
dependencies, and never touches `/app`. Semgrep is only needed by
`runtime` — the `builder` stage has no Python involved in building
LaraDogs itself, so nothing was added there.

Verified with a real `docker compose build` + a running (non-root,
healthy) container:

- `semgrep --version` → `1.176.0`; `composer --version` → `2.10.3`;
  `node --version` → `v22.23.2`; `npm --version` → `10.9.8` — **no
  Composer/npm regression** from adding Semgrep to the same `runtime`
  stage.
- `php artisan laradogs:audit <fixture> --analyzer=semgrep` against a
  **read-only-mounted** (`:ro`) PHP fixture correctly reported 2 findings
  with `coverage: explicit`.
- Omitting `--analyzer` entirely correctly ran `composer-audit` +
  `npm-audit` + `semgrep` together in one invocation, with each
  analyzer's own applicability/failure behavior against that fixture
  unaffected by the other two being present.
- The read-only-mounted fixture directory was confirmed byte-for-byte
  unmodified after both runs.
- **Image size impact: approximately +382MB (798MB → 1.18GB)** for
  Python 3 + the Semgrep virtualenv — a real, measured, and accepted cost
  of a Python-based static analysis engine; not optimized in this phase
  (no destructive image-slimming attempted).
- All test containers and temp directories created for this verification
  were removed afterward; nothing was left running.

Semgrep's own settings file (`SEMGREP_SETTINGS_FILE`, always forced by
`SemgrepAnalyzer` to `config('laradogs.semgrep.settings_path')`, default
`storage_path('app/laradogs/semgrep-settings.yml')`) needs **zero
Dockerfile changes** — confirmed empirically (mirroring npm's own
`NPM_CONFIG_USERCONFIG` behavior) that Semgrep creates both the parent
directory and the settings file on demand when the path doesn't exist yet,
and `storage/app` is already `laradogs`-owned via the existing
`COPY --chown=laradogs:laradogs . .` step.

## Why not Nginx + PHP-FPM (yet)

Phase 0 explicitly avoids over-building Docker before there's an audit
domain to justify it — see ADR-0005. `php artisan serve` is adequate for
local/self-hosted single-user use. A production-grade Server profile
(Nginx/FPM split, queue workers, scheduler, PostgreSQL, Redis) is future
work, tracked in [`../roadmap/roadmap.md`](../roadmap/roadmap.md).

## Known limitations

- **The `composer` binary was not present in the `runtime` stage —
  fixed in Phase 4.1.** (Historical note: Phase 4 shipped
  `ComposerAuditAnalyzer` without this, since the `builder` stage's
  Composer binary was never copied into `runtime`; see
  [Composer in the runtime image](#composer-in-the-runtime-image) above
  for how this was closed.)
- **Adding Semgrep (Phase 5) grew the `runtime` image by ~382MB
  (798MB → 1.18GB)** — a real, accepted cost of a Python-based static
  analysis engine, not optimized in this phase. No destructive
  image-slimming (multi-stage venv copy-out, Alpine-based runtime, etc.)
  was attempted under this phase's time pressure — see
  [Semgrep in the runtime image](#semgrep-in-the-runtime-image) above.
- `pdo_sqlite`/`sqlite3` and `pdo_mysql` PHP extensions are installed —
  no `pdo_pgsql` in this image yet (see
  [Database support](#database-support) above); a future scanner that
  itself needs a database driver would need the same kind of extension
  addition Composer just got here.
- The build stage temporarily writes a throwaway `.env` (copied from
  `.env.example`) purely so `php artisan key:generate` and Wayfinder's
  route introspection can run during `npm run build`; it's removed before
  the runtime stage copies anything, and no `.env` file exists inside the
  final image. Real configuration is supplied at runtime via
  `docker-compose.yml`'s `env_file: .env`, which the user is expected to
  have created with their own values (including a real `APP_KEY` — see
  above).
- `docker compose build && docker compose up` has been verified
  end-to-end: image builds, container reaches `healthy`, and `GET /up`
  returns `200` on a fresh volume. Two bugs found during that verification
  were fixed:
    1. The `runtime` stage installed `libsqlite3-0` (runtime lib) but not
       `libsqlite3-dev`/`pkg-config`, so `docker-php-ext-install pdo_sqlite`
       failed to configure. Fixed by installing the dev headers and
       `pkg-config` for the build, then purging them
       (`apt-get purge -y --auto-remove`) so the final layer keeps only the
       runtime `.so`.
    2. `WORKDIR /app` runs as root before `USER laradogs` is set, so `/app`
       itself (not just the subdirectories explicitly `chown`'d) was
       root-owned; the entrypoint's old `cp .env.example .env` step failed
       with `Permission denied`. Fixed by `chown laradogs:laradogs /app`
       right after the `WORKDIR`, and by removing the `.env`/`APP_KEY`
       generation logic from the entrypoint entirely (see above — it could
       never have worked given `env_file`-supplied configuration; it just
       printed a confusing error while the app still ran, because
       `APP_KEY` was in fact already present as a real environment
       variable).
