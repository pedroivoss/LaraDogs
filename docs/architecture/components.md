# Components (Current State)

This describes what exists in the repository today — the Laravel starter
kit foundation from Phase 0, plus Project Discovery (Phase 1) — not the
full target audit architecture. See [`overview.md`](overview.md) for that.

## Backend (`app/`)

| Path                             | Purpose                                                                                                                |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `app/Actions/Fortify/`           | Fortify action classes (user creation, password validation/reset) — starter-kit auth, not LaraDogs-specific.           |
| `app/Audit/Discovery/`           | **Project Discovery Core** (Phase 1) — see below.                                                                      |
| `app/Http/Controllers/`          | Inertia page controllers and Fortify-adjacent controllers.                                                             |
| `app/Http/Controllers/Settings/` | User settings pages (profile, password, appearance, two-factor, passkeys).                                             |
| `app/Http/Middleware/`           | `HandleAppearance` (theme cookie) and `HandleInertiaRequests` (shared Inertia props).                                  |
| `app/Http/Requests/`             | Form request validation classes.                                                                                       |
| `app/Models/`                    | Currently only `User`.                                                                                                 |
| `app/Providers/`                 | `AppServiceProvider`, Fortify service provider bindings.                                                               |
| `app/Console/Commands/`          | `InspectProjectCommand` (`laradogs:inspect`) — thin CLI adapter over Project Discovery, no detection logic of its own. |

### Project Discovery (`app/Audit/Discovery/`)

The first real Audit Core code (see
[ADR-0002](decisions/ADR-0002-application-architecture.md)): a static,
evidence-based stack detector with no dependency on the web UI, MCP, or a
database. Full detail, security model, and evidence sources:
[`../auditing/project-discovery.md`](../auditing/project-discovery.md);
the decision behind _how_ it's safe to run against untrusted code:
[ADR-0008](decisions/ADR-0008-static-project-discovery.md).

| Path                               | Purpose                                                                                                                                                                                                                 |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ProjectDiscovery.php`             | Entry point — orchestrates the inspectors below into a `DiscoveryResult`.                                                                                                                                               |
| `Filesystem/ProjectFilesystem.php` | Bounded, rooted, read-only file access — the only way this code touches the target's filesystem.                                                                                                                        |
| `Manifests/`                       | `ComposerManifest`/`NpmManifest` — safe JSON parsing of `composer.json`/`composer.lock`/`package.json`.                                                                                                                 |
| `Inspectors/`                      | `ComposerInspector`, `LaravelInspector`, `FrontendInspector`, `TestingInspector`, `InfrastructureInspector`, `DatabaseInspector` — one concern each.                                                                    |
| `Profile/`                         | `ProjectProfile` and its sections (`BackendProfile`, `FrontendProfile`, `TestingProfile`, `InfrastructureProfile`, `DatabaseProfile`), `ProfileBuilder`, and the `ProjectType`/`DatabaseDriver`/`PackageManager` enums. |
| `Support/`                         | `Detection`/`VersionDetection` value objects, `DetectionStatus` enum, `DiscoveryIssue`.                                                                                                                                 |

No `Finding`/`Scan` model or persistence exists yet — that's still Phase 3.

## Frontend (`resources/js/`)

Inertia + React 19 + TypeScript, using shadcn/ui components under
`components/ui/`.

| Path                                | Purpose                                                                                                                                                                                                                                              |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pages/`                            | One Inertia page per route: `welcome.tsx`, `dashboard.tsx`, `auth/*`, `settings/*`.                                                                                                                                                                  |
| `layouts/`                          | `app-layout.tsx` (sidebar/header variants) and `auth-layout.tsx` (simple/card/split variants) — see the starter kit's own customization docs for switching variants.                                                                                 |
| `components/`                       | Reusable UI: sidebar/nav, two-factor and passkey management, shadcn primitives in `components/ui/`.                                                                                                                                                  |
| `hooks/`                            | `use-appearance`, `use-two-factor-auth`, `use-clipboard`, etc.                                                                                                                                                                                       |
| `actions/`, `routes/`, `wayfinder/` | **Generated at build time** by Laravel Wayfinder (`php artisan wayfinder:generate`), gitignored. Provide type-safe route/action helpers callable from TypeScript — regenerate with `npm run build` or `npm run dev` after adding routes/controllers. |
| `types/`                            | Shared TypeScript types (`auth.ts`, `navigation.ts`, `ui.ts`).                                                                                                                                                                                       |

There is **no `Dashboard/`, `Findings/`, `Scans/` page group yet** — that's
Phase 7.

## Routing (`routes/`)

- `web.php` — home (`/`) and dashboard (`/dashboard`, `auth`+`verified`
  middleware).
- `settings.php` — profile/password/appearance/two-factor/passkey settings.
- `console.php` — Artisan console routes (scheduled commands would go
  here; none defined yet).
- The `/up` health-check route is registered in `bootstrap/app.php` via
  Laravel's built-in `health:` routing option, not a custom controller.

## Database

- Driver today: SQLite (`database/database.sqlite`, gitignored via
  `database/.gitignore`), selected via `DB_CONNECTION=sqlite` in
  `.env.example` — the Quick Start default, not an architectural
  requirement. MySQL, MariaDB, and PostgreSQL are equally supported
  through the same `config/database.php` (stock Laravel connections, no
  LaraDogs-specific code); see
  [ADR-0007](decisions/ADR-0007-database-agnostic-persistence.md).
  The current Docker quick-start image only bundles the `pdo_sqlite`
  extension — see [`../development/docker.md`](../development/docker.md).
- Migrations present are all starter-kit ones: users, cache, jobs,
  passkeys, two-factor columns. **No audit-domain migrations exist.**

## Testing

- Pest, under `tests/Feature` and `tests/Unit`. Starter-kit auth flows
  (login, registration, password reset, email verification, two-factor,
  passkeys, settings) — 39 tests from Phase 0 — plus Project Discovery
  (`tests/Unit/Audit/Discovery/`, `tests/Feature/Console/`) — 26 tests
  from Phase 1, including a dedicated no-code-execution guarantee test.
  65 tests total, all passing. See
  [`../development/testing.md`](../development/testing.md).
- `tests/Fixtures/discovery/` — small, synthetic project fixtures (never
  real projects) used only by Discovery's tests.

## Tooling already wired by the starter kit

- **Pint** (PHP formatting) — `composer lint` / `composer lint:check`.
- **Larastan/PHPStan** (static analysis) — `composer types:check`.
- **ESLint + Prettier (via `vp check`)** — `npm run check`.
- **Composer `test` script** chains config-clear → lint:check → types:check
  → `php artisan test`, giving one command for the full local gate.

None of this is LaraDogs-specific tooling — it's the standard starter-kit
developer experience, reused as-is rather than replaced (see
[`../development/conventions.md`](../development/conventions.md)).
