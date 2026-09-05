# Components (Current State)

This describes what exists in the repository today — the Laravel starter
kit foundation from Phase 0 — not the target audit architecture. See
[`overview.md`](overview.md) for that.

## Backend (`app/`)

| Path                             | Purpose                                                                                                      |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `app/Actions/Fortify/`           | Fortify action classes (user creation, password validation/reset) — starter-kit auth, not LaraDogs-specific. |
| `app/Http/Controllers/`          | Inertia page controllers and Fortify-adjacent controllers.                                                   |
| `app/Http/Controllers/Settings/` | User settings pages (profile, password, appearance, two-factor, passkeys).                                   |
| `app/Http/Middleware/`           | `HandleAppearance` (theme cookie) and `HandleInertiaRequests` (shared Inertia props).                        |
| `app/Http/Requests/`             | Form request validation classes.                                                                             |
| `app/Models/`                    | Currently only `User`.                                                                                       |
| `app/Providers/`                 | `AppServiceProvider`, Fortify service provider bindings.                                                     |
| `app/Console/Commands/`          | Empty — no custom Artisan commands yet.                                                                      |

There is **no `app/Audit/` (or equivalent) namespace yet** — that's Phase 2.

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

- Driver: SQLite (`database/database.sqlite`, gitignored via
  `database/.gitignore`).
- Migrations present are all starter-kit ones: users, cache, jobs,
  passkeys, two-factor columns. **No audit-domain migrations exist.**

## Testing

- Pest, under `tests/Feature` and `tests/Unit`. Current tests cover
  starter-kit auth flows (login, registration, password reset, email
  verification, two-factor, passkeys, settings) — 39 tests, all passing as
  of Phase 0. See [`../development/testing.md`](../development/testing.md).

## Tooling already wired by the starter kit

- **Pint** (PHP formatting) — `composer lint` / `composer lint:check`.
- **Larastan/PHPStan** (static analysis) — `composer types:check`.
- **ESLint + Prettier (via `vp check`)** — `npm run check`.
- **Composer `test` script** chains config-clear → lint:check → types:check
  → `php artisan test`, giving one command for the full local gate.

None of this is LaraDogs-specific tooling — it's the standard starter-kit
developer experience, reused as-is rather than replaced (see
[`../development/conventions.md`](../development/conventions.md)).
