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

];
