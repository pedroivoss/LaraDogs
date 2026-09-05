# Docker (Personal Profile)

Phase 0 ships a single-container Docker setup for local/self-hosted use
with SQLite. This is the "Personal" profile described in
[ADR-0005](../architecture/decisions/ADR-0005-persistence-and-deployment-profiles.md).
There is no "Server" profile (PostgreSQL, Redis, workers, scheduler,
reverse proxy) yet — see the roadmap.

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

Two-stage build (`Dockerfile`):

1. **`builder`** — `php:8.3-cli-bookworm` + Node 22, installs Composer and
   npm dependencies, builds the Vite bundle (this is also where Wayfinder
   generates typed route helpers, which requires the full application code
   to introspect routes/controllers), then dumps an optimized
   classmap-authoritative autoloader. Discarded after build.
2. **`runtime`** — a fresh `php:8.3-cli-bookworm` with only `pdo_sqlite`,
   `sqlite3`, and `curl` (for the healthcheck) added. Runs as a non-root
   user (`laradogs`, uid 1000), not root. No Node, no dev dependencies,
   no build toolchain ship in this layer.

A `HEALTHCHECK` hits the app's built-in `/up` route (registered via
Laravel's `health:` routing option in `bootstrap/app.php`, not custom
code).

## Why not Nginx + PHP-FPM (yet)

Phase 0 explicitly avoids over-building Docker before there's an audit
domain to justify it — see ADR-0005. `php artisan serve` is adequate for
local/self-hosted single-user use. A production-grade Server profile
(Nginx/FPM split, queue workers, scheduler, PostgreSQL, Redis) is future
work, tracked in [`../roadmap/roadmap.md`](../roadmap/roadmap.md).

## Known limitations

- The build stage temporarily writes a throwaway `.env` (copied from
  `.env.example`) purely so `php artisan key:generate` and Wayfinder's
  route introspection can run during `npm run build`; it's removed before
  the runtime stage copies anything, and no `.env` file exists inside the
  final image. Real configuration is supplied at runtime via
  `docker-compose.yml`'s `env_file: .env`, which the user is expected to
  have created with their own values.
- **`docker compose build` could not be verified end-to-end in the Phase 0
  development environment.** `docker compose config` validates cleanly
  (confirms the YAML, env interpolation, volumes, and port mapping are all
  correct), and the `Dockerfile` was reviewed instruction-by-instruction,
  but the actual build hung indefinitely — over 20 minutes with zero
  progress and no network activity — while Docker Desktop's BuildKit
  attempted to resolve/pull base images from Docker Hub. To isolate
  whether this was specific to our Dockerfile, a plain `docker pull
hello-world` (no relation to this project) was attempted directly and
  hung the same way, while `curl` from the host to
  `registry-1.docker.io` and `auth.docker.io` succeeded immediately. This
  points to a networking issue inside the Docker Desktop Linux VM in this
  particular session (not this project's Docker files), most likely
  requiring a Docker Desktop restart to clear. That restart was
  **deliberately not performed** during Phase 0, because this machine's
  Docker Desktop instance also hosts running containers for other,
  unrelated projects (`webserver-db-1`, `webserver-redis-server-1`,
  `webserver-php-1`, `webserver-docs-mcp-1`) that a restart would
  interrupt — outside this task's authorized scope. **Action for whoever
  picks this up:** restart Docker Desktop (or otherwise reset its VM
  networking) in an environment where that's safe, then re-run `docker
compose up --build` — the Dockerfile/Compose files themselves are
  believed correct pending that verification.
