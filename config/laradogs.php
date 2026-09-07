<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LaraDogs Version
    |--------------------------------------------------------------------------
    |
    | Recorded on every Scan so a finding is reproducible against the
    | application state that produced it. LaraDogs has no release/tagging
    | scheme yet (pre-1.0, no scanners exist), so this is a placeholder
    | constant rather than a resolved git tag/composer version — the Scan
    | column exists now so history stays meaningful once real versioning
    | exists, without a schema change.
    |
    */

    'version' => env('LARADOGS_VERSION', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Process Execution
    |--------------------------------------------------------------------------
    |
    | Defaults for App\Audit\Engine\Process\ProcessRunner implementations.
    | `env_allowlist` is the fixed set of environment variable NAMES that
    | are ever allowed to reach a child process spawned on LaraDogs' own
    | behalf (e.g. to run `composer audit`) — every other variable LaraDogs
    | itself inherited (including anything from the TARGET's .env, which
    | LaraDogs never reads anyway, but also LaraDogs' own DB_PASSWORD,
    | API keys, etc.) is explicitly suppressed. See
    | docs/development/process-execution.md and ADR-0011.
    |
    */

    'process' => [
        'timeout_seconds' => (int) env('LARADOGS_PROCESS_TIMEOUT_SECONDS', 30),
        'max_output_bytes' => (int) env('LARADOGS_PROCESS_MAX_OUTPUT_BYTES', 5_000_000),
        'env_allowlist' => [
            'PATH',
            'HOME',
            'COMPOSER_HOME',
            'COMPOSER_CACHE_DIR',
            'SSL_CERT_FILE',
            'SSL_CERT_DIR',
            'HTTP_PROXY',
            'HTTPS_PROXY',
            'NO_PROXY',
            'http_proxy',
            'https_proxy',
            'no_proxy',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Composer Audit Analyzer
    |--------------------------------------------------------------------------
    |
    | `binary` overrides automatic resolution (see ComposerBinaryResolver) —
    | it must point at an executable LaraDogs itself trusts, never anything
    | read from a target project. Left null, LaraDogs looks for `composer`
    | on its OWN PATH via Symfony's ExecutableFinder.
    |
    */

    'composer' => [
        'binary' => env('LARADOGS_COMPOSER_BINARY'),
        'timeout_seconds' => (int) env('LARADOGS_COMPOSER_TIMEOUT_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Npm Audit Analyzer
    |--------------------------------------------------------------------------
    |
    | `binary` overrides automatic resolution (see NpmBinaryResolver) —
    | never `./node_modules/.bin/npm` from a target, never a path read from
    | the target's own package.json. Left null, LaraDogs looks for `npm` on
    | its OWN PATH via Symfony's ExecutableFinder.
    |
    | `registry` is always passed as an explicit `--registry=` flag (the
    | highest-precedence form of npm config) so a target project's own
    | `.npmrc` registry override can never redirect audit queries to a
    | server it controls — verified empirically, see
    | docs/auditing/analyzers/npm-audit.md.
    |
    | `userconfig_path`/`cache_path` are always forced (never merely
    | forwarded) into the child process as NPM_CONFIG_USERCONFIG/
    | NPM_CONFIG_CACHE — neither path needs to exist ahead of time (npm
    | treats a missing per-user config file as "no per-user config", and
    | creates its cache directory on demand) — so a developer's own real
    | $HOME/.npmrc (which may carry registry auth tokens) is never read,
    | and npm's cache never lands inside the target or LaraDogs' own code.
    |
    | `proxy`/`https_proxy` (Phase 4.2.1): left null, `--proxy=false
    | --https-proxy=false` is always passed explicitly — verified
    | empirically that a target's own `.npmrc` `proxy=`/`https-proxy=`
    | would otherwise route the (correctly `--registry=`-pinned) audit
    | request through a server the target controls, defeating the
    | registry pin. If an operator genuinely needs LaraDogs itself to
    | reach the registry through a real, trusted proxy, set these here —
    | never left to whatever LaraDogs' own environment or the target's
    | `.npmrc` happens to set.
    |
    */

    'npm' => [
        'binary' => env('LARADOGS_NPM_BINARY'),
        'timeout_seconds' => (int) env('LARADOGS_NPM_TIMEOUT_SECONDS', 30),
        'registry' => env('LARADOGS_NPM_REGISTRY', 'https://registry.npmjs.org'),
        'userconfig_path' => env('LARADOGS_NPM_USERCONFIG_PATH', storage_path('app/laradogs/empty.npmrc')),
        'cache_path' => env('LARADOGS_NPM_CACHE_PATH', storage_path('app/laradogs/npm-cache')),
        'proxy' => env('LARADOGS_NPM_PROXY'),
        'https_proxy' => env('LARADOGS_NPM_HTTPS_PROXY'),
    ],

];
