# Docker (Personal Profile)

Phase 0 ships a single-container Docker setup for local/self-hosted use,
running SQLite. The single-container shape is the "Personal" profile
described in
[ADR-0005](../architecture/decisions/ADR-0005-persistence-and-deployment-profiles.md).
SQLite is this image's current **implementation** choice, not an
architectural one — LaraDogs officially supports MySQL, MariaDB, and
PostgreSQL too; see
[ADR-0007](../architecture/decisions/ADR-0007-database-agnostic-persistence.md)
and [Database support](#database-support) below. There is no "Server"
profile (Redis, workers, scheduler, reverse proxy) yet — see the roadmap.

## Start

```bash
cp .env.example .env
php artisan key:generate --show   # copy the output into APP_KEY in .env
docker compose up --build
```

Visit `http://localhost:8000` (override the host port with `APP_PORT` in
`.env` if 8000 is taken).

Configuration — including `APP_KEY` — is supplied entirely via
`docker-compose.yml`'s `env_file: .env`, i.e. real process environment
variables inside the container. There is no `.env` file inside the image
or container itself, so `APP_KEY` must already be set in the host's `.env`
before starting; the entrypoint fails fast with a clear error if it isn't
(a blank key would otherwise silently break session/cookie encryption).

The container's entrypoint (`docker/entrypoint.sh`) is idempotent: on every
start it creates `database/database.sqlite` if missing and runs `php
artisan migrate --force`. It's safe to run repeatedly.

## Database support

This image currently installs only the `pdo_sqlite`/`sqlite3` PHP
extensions in the `runtime` stage, so it only runs SQLite out of the box.
This is a **current implementation limitation of this Docker image**, not
an architectural restriction — LaraDogs itself supports MySQL, MariaDB,
and PostgreSQL equally (ADR-0007). Adding the `pdo_mysql`/`pdo_pgsql`
extensions to the runtime stage is straightforward future work, not done
in this pass to avoid unrelated Docker changes.

To use MySQL/MariaDB/PostgreSQL today, run LaraDogs outside this image
(see [`setup.md`](setup.md)) with `DB_CONNECTION`/`DB_HOST`/`DB_DATABASE`/
etc. pointed at an external database instance that has the matching PHP
PDO extension available.

## Stop

```bash
docker compose down
```

Data persists in two named Docker volumes (`laradogs-database`,
`laradogs-storage`) across `up`/`down` cycles. To fully reset local data:

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
   `sqlite3`, `curl` (for the healthcheck), and (Phase 4.1) the real
   `composer` binary added. Runs as a non-root user (`laradogs`, uid
   1000), not root. No Node, no dev dependencies, no build toolchain ship
   in this layer.

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
- Only `pdo_sqlite`/`sqlite3` PHP extensions are installed — no
  `pdo_mysql`/`pdo_pgsql` in this image (see
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
