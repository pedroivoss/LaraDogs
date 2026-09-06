# LaraDogs — "Personal" profile image.
#
# Single-container build meant for local/self-hosted quick start, running
# SQLite. SQLite is this image's current implementation choice, not an
# architectural requirement — see docs/architecture/decisions/ADR-0007
# (MySQL/MariaDB/PostgreSQL are equally supported by LaraDogs, just not yet
# wired into this image) and docs/development/docker.md.
# Not tuned for high-concurrency production traffic (see docs/development/docker.md
# and the future "Server" profile in the roadmap).

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

# ---------------------------------------------------------------------------
# Stage 1: builder — installs PHP + Node toolchains, builds vendor/ and the
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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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
# Stage 2: runtime — slim image with only what's needed to run the app.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-0 \
        libsqlite3-dev \
        pkg-config \
        sqlite3 \
        curl \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
    && apt-get purge -y --auto-remove libsqlite3-dev pkg-config

RUN groupadd --gid 1000 laradogs \
    && useradd --uid 1000 --gid laradogs --shell /bin/bash --create-home laradogs

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
