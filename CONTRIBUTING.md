# Contributing to LaraDogs

Thanks for your interest — LaraDogs is in **early development**
(Phase 0: foundation bootstrap). There is no audit-domain code yet, so the
most valuable contributions right now are likely discussion/design
feedback on the [roadmap](docs/roadmap/roadmap.md) and
[ADRs](docs/architecture/decisions/), not large feature PRs.

## Before you start

1. Read [`docs/architecture/overview.md`](docs/architecture/overview.md)
   and the ADRs under [`docs/architecture/decisions/`](docs/architecture/decisions/)
   — they explain _why_ the project is shaped the way it is, which matters
   more than usual here since so little is built yet.
2. Check [`docs/roadmap/phases.md`](docs/roadmap/phases.md) to see which
   phase your idea belongs to. A change that jumps ahead of the current
   phase (e.g. a scanner integration before the `Finding` model exists)
   is likely to be asked to wait or to be re-scoped.
3. For anything non-trivial, open an issue/discussion first rather than
   sending a large unsolicited PR — this project's shape is still being
   actively decided.

## Local setup

See [`docs/development/setup.md`](docs/development/setup.md) (no Docker)
or [`docs/development/docker.md`](docs/development/docker.md).

## Before opening a PR

```bash
composer run test     # config:clear -> Pint check -> Larastan -> Pest
npm run check
npm run types:check
```

All three must pass — this is exactly what `.github/workflows/tests.yml`
runs in CI. See [`docs/development/conventions.md`](docs/development/conventions.md)
for what each check does and how to fix common failures.

## Conventions

- Code, identifiers, comments, and commit messages: **English**.
- Follow the existing Laravel/starter-kit structure and style (Pint,
  Larastan) rather than introducing a new one.
- Keep PRs scoped to one concern. If you spot something worth doing that's
  outside your PR's scope, note it as a suggestion (an issue, or a line in
  the roadmap's deferred-items list) instead of folding it in.
- **Core principle:** any audit-domain code you add must be able to run
  without an LLM/AI API key. If a contribution requires an AI provider to
  function, it belongs in an MCP-adjacent optional layer, not the Audit
  Core — see [ADR-0002](docs/architecture/decisions/ADR-0002-application-architecture.md).

## Security issues

Do not open a public issue for a security vulnerability — see
[`SECURITY.md`](SECURITY.md).

## License

By contributing, you agree your contributions are licensed under this
project's [MIT License](LICENSE).
