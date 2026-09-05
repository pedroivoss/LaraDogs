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

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi

php artisan migrate --force

exec "$@"
