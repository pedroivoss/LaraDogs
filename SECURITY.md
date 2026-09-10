# Security Policy

LaraDogs is a security tool, so its own security matters more than usual —
please report vulnerabilities responsibly.

## Reporting a Vulnerability

**Do not open a public GitHub issue for a security vulnerability.**

Instead, email **contact@ivocompany.com** with:

- A description of the vulnerability and its potential impact.
- Steps to reproduce (a minimal repro is very helpful).
- The version/commit you tested against.

You should expect an acknowledgement within a few days. As this is an
early-stage, single-maintainer open-source project, there is no formal SLA
yet — this will be revisited as the project matures (see
[`docs/roadmap/roadmap.md`](docs/roadmap/roadmap.md), Phase 13).

## Supported Versions

LaraDogs is in **early development** (pre-1.0). There is no stable release
line yet, and no version currently receives security patches beyond
"latest `main`." This section will be filled in once versioned releases
exist.

## Scope

Currently in scope: this repository's own application code (authentication,
session handling, configuration, Docker setup). **Out of scope for now**,
because it doesn't exist yet: scanner sandboxing/isolation, MCP credential
handling, and anything else described as "Planned" in
[`docs/architecture/security-model.md`](docs/architecture/security-model.md)
— those become in-scope once implemented.

## Known Limitations (Phase 0)

See [`docs/architecture/security-model.md`](docs/architecture/security-model.md)
for the current, honest list — including that public self-registration is
enabled by default (starter-kit behavior), which is a real concern for any
installation reachable beyond local development and is tracked for Phase 10.
