#!/usr/bin/env bash
set -euo pipefail

# Personal-profile bootstrap: prepares storage on a fresh checkout and stays
# idempotent on every subsequent restart.
#
# Configuration (including APP_KEY) is supplied entirely via docker-compose's
# `env_file: .env`, i.e. real process environment variables — there is no
# `.env` file inside the image or container. Laravel's config already reads
# from that environment, so nothing here needs to write one. Attempting to
# `key:generate` against a local file would be a no-op anyway: Laravel loads
# config from the already-set environment variables, immutable, before any
# in-container file could affect it.
if [ -z "${APP_KEY:-}" ]; then
    echo "error: APP_KEY is not set. Generate one with 'php artisan key:generate --show' and set it in .env before running 'docker compose up'." >&2
    exit 1
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi

# `docker compose up` waits for `db`'s healthcheck (condition:
# service_healthy) before starting `app` at all, but `docker compose
# restart` power-cycles both containers together without honoring that
# dependency — so a coordinated restart can briefly hit a still-starting
# `db` here. Bounded retry (not an infinite loop) rather than failing fast
# and relying solely on Docker's outer `restart: unless-stopped` to paper
# over it; `migrate --force` is idempotent, so retrying it is safe.
attempt=0
until php artisan migrate --force; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 15 ]; then
        echo "error: database still unreachable after ${attempt} attempts; giving up." >&2
        exit 1
    fi
    echo "warning: migration attempt ${attempt} failed (database not ready yet?) — retrying in 2s..." >&2
    sleep 2
done

exec "$@"
