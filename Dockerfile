# LaraDogs — "Personal" profile image.
#
# Runtime ships both `pdo_sqlite` and `pdo_mysql` (Phase 7.1.1). The
# docker-compose "Personal" profile pairs this image with a dedicated
# MySQL service for realistic self-hosted UAT; SQLite remains fully
# supported (e.g. outside Docker) — neither is an architectural
# requirement, see docs/architecture/decisions/ADR-0007
# (MySQL/MariaDB/PostgreSQL are equally supported by LaraDogs) and
# docs/development/docker.md. `pdo_pgsql` is still not wired into this
# image.
# Not tuned for high-concurrency production traffic (see docs/development/docker.md
# and the future "Server" profile in the roadmap).

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22
# Pinned, reproducible Composer version for BOTH stages (single source of
# truth — see docs/development/docker.md#composer-in-the-runtime-image).
# `App\Audit\Analyzers\Composer\ComposerAuditAnalyzer::MIN_SUPPORTED_VERSION`
# requires >= 2.4.0; this is pinned well above that floor. Bump deliberately,
# not via a floating tag (`composer:2`/`composer:latest`), so a rebuild
# months from now can't silently ship a different Composer than the one
# this image was last verified against.
ARG COMPOSER_VERSION=2.10.3
# Pinned Semgrep CLI version (Phase 5 —
# App\Audit\Analyzers\Semgrep\SemgrepAnalyzer::MIN_SUPPORTED_VERSION), never
# a floating `pip install semgrep` with no version pin. Semgrep is Python-based
# and needed only by the RUNTIME stage (auditing target projects) — never by
# the builder stage, which has no Python involved in building LaraDogs itself.
ARG SEMGREP_VERSION=1.176.0

# Named stage (not a bare `COPY --from=composer:${COMPOSER_VERSION}`)
# because BuildKit doesn't support variable expansion directly in
# `--from` — this is the documented workaround, and it also means the
# pinned image is fetched exactly once regardless of how many later
# stages need a file from it.
FROM composer:${COMPOSER_VERSION} AS composer_bin

# ---------------------------------------------------------------------------
# Stage 2: builder — installs PHP + Node toolchains, builds vendor/ and the
# frontend bundle. Discarded after the build; nothing here ships to runtime.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-bookworm AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        ca-certificates \
        curl \
        gnupg \
        libsqlite3-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" pdo_sqlite zip

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

ARG NODE_VERSION
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install PHP dependencies first so this layer is cached while iterating on
# application code.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# Now copy the rest of the application. Frontend build needs the full app
# (routes/controllers) because Wayfinder generates typed route helpers by
# introspecting the Laravel application via `php artisan`.
COPY . .

COPY package.json package-lock.json ./
RUN npm ci

RUN cp .env.example .env \
    && php artisan key:generate --ansi \
    && npm run build \
    && rm .env

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------------------
# Stage 3: runtime — slim image with only what's needed to run the app.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-0 \
        libsqlite3-dev \
        default-libmysqlclient-dev \
        pkg-config \
        sqlite3 \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite pdo_mysql \
    && apt-get purge -y --auto-remove libsqlite3-dev default-libmysqlclient-dev pkg-config

# Node/npm for `npm audit` (Phase 4.2 — App\Audit\Analyzers\Npm\NpmAuditAnalyzer).
# Installed directly here (not copied from `builder`, unlike Composer's
# single-file binary): npm is not one file — `/usr/bin/npm` is a thin
# wrapper around a full `/usr/lib/node_modules/npm/` tree, so "copy just
# the binary" doesn't work the way it does for Composer's PHAR. Reuses the
# SAME NODE_VERSION major-version pin already used by the `builder` stage
# (not a new, separate version knob) via the same NodeSource setup
# script/apt mechanism already used there — this installs the current
# 22.x release, matching this Dockerfile's existing PHP_VERSION precision
# (a pinned major/minor line, not an exact patch), not `latest`. `gnupg` is
# only needed transiently for the NodeSource repo setup script, purged in
# this same layer once nodejs itself is installed.
ARG NODE_VERSION
RUN apt-get update && apt-get install -y --no-install-recommends gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get purge -y --auto-remove gnupg \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid 1000 laradogs \
    && useradd --uid 1000 --gid laradogs --shell /bin/bash --create-home laradogs

# Semgrep for static analysis (Phase 5 — App\Audit\Analyzers\Semgrep\SemgrepAnalyzer).
# Semgrep's own official Docker image (semgrep/semgrep) is Alpine/musl-based
# and ships a thin Python entrypoint backed by a full site-packages tree
# (verified by inspecting it directly — `/usr/bin/semgrep` is a `#!/usr/bin/python3`
# script, not a standalone binary the way Composer's PHAR is) — its Python
# build is not portable to this image's glibc/Debian base, so "copy just the
# binary from the official image" (Composer's strategy) does not apply here.
# Instead: a dedicated virtualenv under `/opt/semgrep-venv`, entirely isolated
# from both the system Python and LaraDogs' own PHP/Node dependencies (never
# touches `/app`), with `pip install` pinned to the exact `SEMGREP_VERSION`
# above — never `pip install semgrep` unpinned, and never `--pre`/`latest`.
ARG SEMGREP_VERSION
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 \
        python3-venv \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv /opt/semgrep-venv \
    && /opt/semgrep-venv/bin/pip install --no-cache-dir "semgrep==${SEMGREP_VERSION}"

ENV PATH="/opt/semgrep-venv/bin:${PATH}"

# The real Composer binary this image ships to run `composer audit`
# against a target project — reused from the builder stage (same pinned
# COMPOSER_VERSION, one image pull, not a second one) rather than copying
# the builder's entire toolchain. See docs/development/docker.md.
COPY --from=builder /usr/bin/composer /usr/bin/composer

# A fixed, LaraDogs-controlled Composer cache/config home — never inside
# `/app` (this image's own code) and never inside a future target mount.
# Set explicitly (rather than relying on Composer's own $HOME-derived
# default resolution, which has changed between versions) so this stays
# true regardless of Composer version. Already covered by the existing
# `laradogs.process.env_allowlist` (COMPOSER_HOME) with no code change.
ENV COMPOSER_HOME=/home/laradogs/.composer
RUN mkdir -p "$COMPOSER_HOME" && chown -R laradogs:laradogs /home/laradogs

WORKDIR /app
RUN chown laradogs:laradogs /app

COPY --from=builder --chown=laradogs:laradogs /app/vendor ./vendor
COPY --from=builder --chown=laradogs:laradogs /app/public/build ./public/build
COPY --chown=laradogs:laradogs . .

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache database \
    && chown -R laradogs:laradogs storage bootstrap/cache database

COPY docker/entrypoint.sh /usr/local/bin/laradogs-entrypoint
RUN chmod +x /usr/local/bin/laradogs-entrypoint

USER laradogs

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

ENTRYPOINT ["laradogs-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
