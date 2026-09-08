<?php

// Fixture for laradogs.configuration.debug.app-debug-default-true.
// This deliberately mimics config/*.php file shapes — never a real .env
// file, which SemgrepTargetCollector never collects and this rule never
// reads. Positive cases below must be flagged; negative/safe cases must
// not.

class ConfigShapes
{
    // POSITIVE: debug defaults to true when APP_DEBUG is unset.
    public static function positiveDefaultTrue(): array
    {
        return [
            'debug' => (bool) env('APP_DEBUG', true),
        ];
    }

    // NEGATIVE: this is exactly Laravel's own real skeleton default — debug
    // defaults to false when APP_DEBUG is unset (see this repository's own
    // config/app.php).
    public static function negativeDefaultFalse(): array
    {
        return [
            'debug' => (bool) env('APP_DEBUG', false),
        ];
    }

    // NEGATIVE: no default value at all — env() alone is not flagged; only
    // an explicit `true` second argument is.
    public static function negativeNoDefault(): array
    {
        return [
            'debug' => (bool) env('APP_DEBUG'),
        ];
    }

    // NEGATIVE: an unrelated env() call with a `true` default — this rule
    // is deliberately scoped to APP_DEBUG specifically, not every env()
    // call defaulted to true.
    public static function negativeUnrelatedKey(): array
    {
        return [
            'some_other_flag' => (bool) env('SOME_OTHER_FLAG', true),
        ];
    }
}
