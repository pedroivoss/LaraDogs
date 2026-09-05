# Testing

## Framework: Pest

LaraDogs uses **Pest** rather than PHPUnit. Both ship "out of the box"
with Laravel and either is fully supported; Pest was chosen because it's
the framework every current Laravel starter kit and doc sample defaults
to. See [ADR-0001](../architecture/decisions/ADR-0001-bootstrap-stack.md)
for the full reasoning, including why Composer resolved `pestphp/pest
^4.7` instead of the newest `^5.x` (PHP 8.4 requirement not met by this
environment's PHP 8.3).

PHPUnit is still installed transitively (Pest is built on it) and
`phpunit.xml` exists, so `vendor/bin/phpunit` also works if ever needed —
Laravel doesn't force an either/or at the tooling level.

## Running tests

```bash
php artisan test
# or
./vendor/bin/pest
```

As of Phase 0 (starter-kit auth scaffolding only — no audit domain code
exists to test yet):

```
{"tool":"pest","result":"passed","tests":39,"passed":39,"assertions":136,"duration_ms":1427}
```

All 39 tests are starter-kit coverage (login, registration, password
reset/confirmation, email verification, two-factor challenge/setup,
passkeys, settings pages). None of them exercise LaraDogs-specific
behavior, because none exists yet.

## Parallel / coverage / profiling

Available via Laravel's test runner (not specific to this project, see
[Laravel's testing docs](https://laravel.com/docs/testing)):

```bash
php artisan test --parallel
php artisan test --coverage --min=80   # requires Xdebug or PCOV, neither installed by default here
php artisan test --profile
```

## What "done" will mean for the Audit Core (Phase 2+)

Not applicable yet. When scanner orchestration exists, it must be testable
without any real external scanner installed or any LLM API key present —
per [ADR-0002](../architecture/decisions/ADR-0002-application-architecture.md),
the Audit Core has zero required AI dependency, and CI should be able to
exercise it fully offline.
