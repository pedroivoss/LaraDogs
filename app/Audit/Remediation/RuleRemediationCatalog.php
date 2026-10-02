<?php

namespace App\Audit\Remediation;

use App\Audit\Analyzers\Semgrep\SemgrepRuleCatalog;

/**
 * Structured, LaraDogs-authored remediation guidance for LaraDogs-OWNED rules
 * (Phase 12) — the canonical, deterministic and TRUSTED source of `summary`,
 * `action`, `steps` and `limitations`.
 *
 * `action` is the long-form remediation paragraph of the bundled ruleset
 * (`metadata.remediation` in laradogs-rules.yml — the text Semgrep passes back
 * and the ingestor persists as `findings.recommendation`). The persisted copy
 * is NEVER trusted for guidance (a database value is not a LaraDogs constant);
 * this catalog holds the trusted copy, and tests keep three things in lockstep:
 * {@see SemgrepRuleCatalog}, the bundled YAML text, and this catalog — a rule
 * cannot exist without guidance, and the two paragraphs cannot drift.
 *
 * Everything here is a static constant: nothing is derived from a target,
 * a finding or a file, so it is safe to place in trusted fields. Guidance is
 * deliberately specific ("bind the value as a parameter"), never generic
 * ("fix this vulnerability").
 */
final class RuleRemediationCatalog
{
    private const string HEURISTIC = 'Detection is heuristic (intra-procedural taint analysis): confirm the value really originates from user input and reaches this call before changing code. If it does not, mark the finding as a false positive with a reason.';

    /**
     * @var array<string, array{summary: string, action: string, steps: list<string>, limitations: list<string>, laravel: bool}>
     */
    private const array GUIDANCE = [
        'laradogs.quality.debug.dd-call' => [
            'summary' => 'Remove the dd() debug call; it halts the request that reaches it.',
            'action' => 'Remove the dd() call. If you need to inspect state during debugging, use dump() locally (never committed) or proper structured logging (Log::debug()) for anything meant to persist.',
            'steps' => [
                'Open the flagged file and locate the dd() call at the reported line.',
                'Delete the call. If the information is still needed, log it with Log::debug() and make sure the logged value holds no secrets or personal data.',
                'Search the surrounding change for other leftover debug helpers (dump(), ray(), var_dump()).',
            ],
            'limitations' => [],
            'laravel' => true,
        ],
        'laradogs.quality.debug.var-dump-call' => [
            'summary' => 'Remove the var_dump() call; it prints raw variable state into the response.',
            'action' => 'Remove the var_dump() call. Use Log::debug()/Log::info() for anything that needs to persist, or a debugger/dump() during local development only.',
            'steps' => [
                'Open the flagged file and locate the var_dump() call at the reported line.',
                'Delete the call, or replace it with structured logging (Log::debug()) that omits sensitive values.',
                'Search the surrounding change for other leftover debug helpers (dd(), dump(), ray()).',
            ],
            'limitations' => [],
            'laravel' => false,
        ],
        'laradogs.quality.debug.ray-call' => [
            'summary' => 'Remove the ray() debug call before this code ships.',
            'action' => 'Remove the ray() call before committing. Use Log::debug() for anything that needs to persist beyond local debugging.',
            'steps' => [
                'Open the flagged file and locate the ray() call at the reported line.',
                'Delete the call, or replace it with Log::debug() if the information must persist.',
                'Confirm the spatie/ray dependency is only required in development (require-dev) if nothing else uses it.',
            ],
            'limitations' => [],
            'laravel' => false,
        ],
        'laradogs.security.php.eval-usage' => [
            'summary' => 'Replace eval() with an explicit, restricted alternative; never evaluate a string that user input can influence.',
            'action' => 'Replace eval() with an explicit, restricted alternative: a match/switch over known cases, a whitelisted callable map, or a small, purpose-built parser. Never construct the evaluated string from user input, even partially.',
            'steps' => [
                'Identify what string is evaluated and where every part of it comes from.',
                'Replace the evaluation with an explicit construct: a match/switch over known cases, a whitelisted callable map, or a small purpose-built parser.',
                'Ensure no part of any remaining dynamic string can be influenced by a request, file or database value.',
                'Add a test covering the allowed cases and a rejected unknown case.',
            ],
            'limitations' => ['eval() is flagged wherever it appears; if the string is a compile-time constant the risk is lower, but the call should still be replaced.'],
            'laravel' => false,
        ],
        'laradogs.security.sql.tainted-raw-query' => [
            'summary' => 'Bind untrusted values as query parameters instead of interpolating them into raw SQL.',
            'action' => 'Prefer parameter bindings over raw SQL: whereRaw(\'id = ?\', [$id]) instead of whereRaw("id = $id"). When a raw fragment must include a value that cannot be bound (e.g. a column/direction name), validate it against an explicit allowlist (in_array($value, [\'asc\', \'desc\'], true)) or cast it to the expected scalar type (e.g. (int)) before use.',
            'steps' => [
                'Locate the raw fragment (DB::raw, whereRaw, orderByRaw, selectRaw, havingRaw) and the user-influenced value that reaches it.',
                'Pass the value as a binding (whereRaw(\'id = ?\', [$id])) rather than concatenating or interpolating it.',
                'If a column or direction name must vary, validate it against an explicit allowlist (in_array($value, [\'asc\', \'desc\'], true)); if it is numeric, cast it.',
                'Review other raw fragments in the same query for the same pattern.',
            ],
            'limitations' => [self::HEURISTIC],
            'laravel' => true,
        ],
        'laradogs.security.blade.raw-output-tainted' => [
            'summary' => 'Use escaped Blade output for user-controlled values; reserve {!! !!} for content you have sanitized.',
            'action' => 'Use escaped output ({{ $value }}) unless the value is genuinely trusted HTML (e.g. rendered from a trusted Markdown/HTML sanitizer you control). If raw output is required, sanitize the value first (e.g. with an HTML purifier) rather than echoing user input directly.',
            'steps' => [
                'Locate the {!! ... !!} block and confirm the echoed value comes from a request or other untrusted source.',
                'Switch to escaped output ({{ $value }}).',
                'If raw HTML is genuinely required, sanitize it with an HTML purifier before storing or rendering — never echo request input directly.',
            ],
            'limitations' => [self::HEURISTIC],
            'laravel' => true,
        ],
        'laradogs.security.command.tainted-exec' => [
            'summary' => 'Do not build shell commands from user input; use an argv array (Symfony Process) so there is no shell to inject into.',
            'action' => 'Avoid building shell commands from user input. If unavoidable, use escapeshellarg()/escapeshellcmd() on every user-controlled argument, or — far preferably — use Symfony Process with an argv array (never a shell string) so there is no shell to inject into at all.',
            'steps' => [
                'Locate the exec/system/shell_exec/passthru/proc_open call and the user-influenced value that reaches it.',
                'Prefer removing the shell call entirely (a PHP library or API often replaces it); otherwise run the program through Symfony Process with an argument array, not a command string.',
                'If a shell string is unavoidable, pass every user-controlled argument through escapeshellarg() and validate it against an allowlist first.',
            ],
            'limitations' => [self::HEURISTIC],
            'laravel' => false,
        ],
        'laradogs.security.filesystem.tainted-path' => [
            'summary' => 'Never build a filesystem path from user input; map it to an allowlisted name and keep access inside a fixed root.',
            'action' => 'Never build a filesystem path directly from user input. Use basename() to strip directory components, validate the result against an allowlist of permitted files/extensions, and prefer Laravel\'s Storage facade with a fixed disk root over raw filesystem functions so paths cannot escape the configured root.',
            'steps' => [
                'Locate the filesystem operation and the user-influenced value used as (part of) the path.',
                'Replace the raw path with an identifier looked up in a server-side allowlist or database record, or strip directory components with basename() and validate the name and extension.',
                'Prefer the Storage facade with a fixed disk root over raw filesystem functions, and confirm the resolved path stays inside that root.',
            ],
            'limitations' => [self::HEURISTIC],
            'laravel' => false,
        ],
        'laradogs.security.redirect.tainted-open-redirect' => [
            'summary' => 'Redirect to a named route instead of a user-supplied URL; if a return-to URL is required, accept only same-site relative paths.',
            'action' => 'Prefer redirect()->route(\'name\') or redirect()->action(...) with a named destination over a user-supplied URL. If a user-supplied "return to" URL is genuinely required, validate it is a relative, same-site path (e.g. reject any value containing "://" or starting with "//") before redirecting.',
            'steps' => [
                'Locate the redirect and the user-influenced value that becomes its destination.',
                'Use redirect()->route(\'name\') or redirect()->action(...) with a fixed destination where possible.',
                'If a return-to URL is required, accept only a relative same-site path (reject values containing "://" or starting with "//") before redirecting.',
            ],
            'limitations' => [self::HEURISTIC],
            'laravel' => true,
        ],
        'laradogs.security.mass-assignment.request-all' => [
            'summary' => 'Pass only validated, explicitly selected fields to create()/update() instead of the whole request payload.',
            'action' => 'Pass only validated, explicitly-selected fields — e.g. $request->validate([...]) or $request->only([...]) — instead of ->all(). Review the model\'s $fillable list to confirm it does not include any field that should never be user-settable.',
            'steps' => [
                'Locate the create()/update() call that receives $request->all().',
                'Replace it with $request->validated() (from a validation rule set or Form Request) or $request->only([...]) listing exactly the intended fields.',
                'Review the model\'s $fillable/$guarded so no privileged field (roles, ownership, flags) can be set from a request.',
            ],
            'limitations' => ['This is a review prompt: if $fillable is strictly limited the risk is reduced, but validated input is still the safer contract.'],
            'laravel' => true,
        ],
        'laradogs.configuration.debug.app-debug-default-true' => [
            'summary' => 'Default APP_DEBUG to false so a missing environment variable fails safe; enable debug explicitly per environment.',
            'action' => 'Default APP_DEBUG to false (env(\'APP_DEBUG\', false)) so a missing environment variable fails safe, and set the real value explicitly per environment (true only for local development).',
            'steps' => [
                'Change the default in the flagged env() call to false (env(\'APP_DEBUG\', false)).',
                'Set APP_DEBUG explicitly in each environment (true only for local development).',
                'Verify production configuration does not enable debug mode.',
            ],
            'limitations' => [],
            'laravel' => true,
        ],
        'laradogs.performance.eloquent.unbounded-all' => [
            'summary' => 'Bound the query (paginate, cursor, chunk or limit) unless the table is and will remain small.',
            'action' => 'If this table can grow unbounded, replace ::all() with ::paginate()/::cursor()/a bounded ::limit(), or confirm the table size is and will remain small enough that loading it entirely is intentional.',
            'steps' => [
                'Decide whether the table can grow without bound (users, logs, orders) or is a small, fixed lookup.',
                'If it can grow, replace ::all() with ::paginate(), ::cursor(), chunking or an explicit limit, selecting only the needed columns.',
                'If the table is intentionally small, record that decision by marking the finding with a status and reason instead of changing code.',
            ],
            'limitations' => ['This is a performance review prompt, not a confirmed defect; low confidence by design.'],
            'laravel' => true,
        ],
    ];

    public static function has(string $ruleId): bool
    {
        return isset(self::GUIDANCE[$ruleId]);
    }

    /**
     * @return array{summary: string, action: string, steps: list<string>, limitations: list<string>, laravel: bool}|null
     */
    public static function find(string $ruleId): ?array
    {
        return self::GUIDANCE[$ruleId] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function ruleIds(): array
    {
        return array_keys(self::GUIDANCE);
    }
}
