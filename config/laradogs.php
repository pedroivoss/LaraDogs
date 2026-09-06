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

];
