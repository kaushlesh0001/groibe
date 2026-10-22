# Project rules: Groibe

## What this repo is
- Groibe: managed-delivery platform for skilled Indian professionals and mid-market clients. Open applications are a controlled add-on.
- Single Laravel monolith: public site + contributor app + client app + admin (Filament).
- The source of truth is `docs/GROIBE_MASTER_SPEC.md`. If code and spec disagree, the spec wins. If the spec is silent or contradictory, STOP and ask. Do not guess.

## Non-negotiable working rules
1. Read `docs/GROIBE_MASTER_SPEC.md` end to end at the start of every session and before every pass. Do not generate code from partial reading.
2. Work on ONE pass at a time (see `docs/PROMPT_PACK.md`). Do not start the next pass.
3. Before writing code in a pass, output a short plan: files to create/change, migrations, tests. Wait for no approval; proceed unless a blocker exists.
4. After each pass: run tests, Pint, and Larastan. Report results truthfully, including failures. Never claim something works without running it.
5. Never invent requirements. Never add features outside the spec. Log suggestions in `docs/SUGGESTIONS.md` instead.
6. Never edit the spec yourself. Propose changes in `docs/SPEC_CHANGE_REQUESTS.md`.
7. Never commit secrets, `.env`, or real personal data. Demo data is fake and runs in local/staging only.
8. Small, reviewable commits with clear messages. One concern per commit.

## Stack (verify installed versions, do not assume)
- Laravel 13 (current major), PHP 8.3, MySQL/MariaDB.
- UI: Blade + Livewire + Alpine + Tailwind. Admin: Filament (latest version compatible with the installed Laravel; read its docs for that major, do not reuse older API from memory).
- Packages: spatie/laravel-permission, spatie/laravel-activitylog, spatie/laravel-medialibrary, barryvdh/laravel-dompdf, Pest, Laravel Pint, Larastan.
- After installing anything run `php artisan about` and `composer show` for the versions, and read the package docs/README for that version before coding against it.
- Vite builds assets locally only. The production server never runs Node.

## Hosting constraints (shared cPanel, enforce in code)
- No persistent processes. Queue driver is `database`, drained by cron via the scheduler (`queue:work --stop-when-empty --max-time=50`).
- No Redis, no websockets, no Node at runtime.
- Do not rely on `storage:link` symlinks. Use the disk strategy in spec 17.1.
- Paginate all lists. Eager load. Enable `Model::preventLazyLoading()` outside production.

## Architecture conventions
- Business logic in `app/Actions/<Domain>/<VerbNoun>.php` (single-purpose, invokable). Controllers and Livewire components stay thin.
- State machines: PHP backed Enums in `app/Enums` + one Action per transition. Illegal transitions throw `App\Exceptions\IllegalTransition`. Every transition writes an activity log entry and dispatches a notification via `NotificationDispatcher`.
- Authorization: Policies for every model, used by Livewire, controllers, and Filament alike. No role-name string checks in business code; use `can()`.
- Validation: Form Requests or Livewire form objects. Never validate inline in controllers ad hoc.
- Models: `$fillable` explicit, casts to Enums, relationships typed, scopes for common filters. Public models get ULID `uuid`.
- Money: `decimal(14,2)` + currency. Never floats.
- Files: media library, collections per spec. Private by default. Serve private files only through authorized routes.
- Naming: tables plural snake_case, models singular StudlyCase, enum cases StudlyCase, routes kebab-case, route names dot.notation.
- Views: Blade components under `resources/views/components`. No business logic in Blade.
- Config via `.env` and `config/groibe.php`. No hard-coded emails, URLs, or legal text.
- Legal text lives in DB templates, never in code.

## Folder layout
```
app/
  Actions/{Briefs,Projects,Sows,Staffing,Tasks,Submissions,Reviews,Reputation,Imports,Onboarding}/
  Enums/
  Exceptions/
  Filament/Admin/{Resources,Pages,Widgets}/
  Http/{Controllers,Middleware,Requests}/
  Livewire/{Public,Contributor,Client,Shared}/
  Models/
  Notifications/
  Policies/
  Services/{NotificationDispatcher,FeatureFlags,Settings,SowRenderer,ReputationCalculator}/
  Services/Ai/            (interface placeholder only)
database/{migrations,factories,seeders}/
resources/views/{public,contributor,client,emails,components,layouts}/
routes/{public,contributor,client,auth}.php
tests/{Feature,Unit}/
docs/{GROIBE_MASTER_SPEC.md,PROMPT_PACK.md,SUGGESTIONS.md,SPEC_CHANGE_REQUESTS.md,DEPLOY.md}
```

## Design rules
- Brand tokens in Tailwind config: `brand-navy` `#042B65`, `brand-green` `#0EAE44`, `brand-green-alt` `#25A446`, `brand-blue` `#046CEA`, `brand-teal` `#06A2A7`. Green buttons use navy text. Green is never text on white. Blue links stay on light surfaces.
- Logo from `public/brand` only (SVG on the web, PNG in emails and PDFs). Never retype, recolor or stretch it. Logo files: `groibe-logo-clean.svg` on light surfaces; `groibe-logo-dark.svg`, `groibe-logo-dark.png` and `groibe-logo-dark-small.png` on navy surfaces (use these instead of the all-white logo); `groibe-logo-white.svg` only where a single-colour logo is needed. The old `groibe-logo.svg` is replaced by `groibe-logo-clean.svg`.
- Modern, calm, lots of whitespace, rounded corners, white or deep-navy surfaces. Inter only, self-hosted, no third-party font or script requests.
- Mobile first. WCAG AA contrast. Labels on every input. Keyboard accessible.
- Public copy: no em dashes, no AI-related wording.

## Testing and quality gates (every pass)
- `php artisan test` (Pest) green.
- `./vendor/bin/pint --test` clean.
- Larastan level 5 clean.
- Required tests per pass are listed in the prompt pack. At minimum: every new state transition, every policy rule, and each new route's auth behavior (guest, wrong role, right role).
- Add a negative test for every access rule (cross-org access, internal data leaks, non-active contributor).

## Commands
- Dev: `composer dev` (or `php artisan serve` + `npm run dev`)
- Tests: `php artisan test`
- Never run `php artisan test` (it runs out of memory); run Pest folder by folder, for example `php -d memory_limit=1G vendor/bin/pest tests/Feature/Agreements`.
- Lint: `./vendor/bin/pint` ; Static: `./vendor/bin/phpstan analyse`
- Fresh demo DB (local only): `php artisan migrate:fresh --seed --seeder=DemoSeeder`
- Prod build: `npm run build` (local), then deploy per `docs/DEPLOY.md`

## When blocked
- State exactly what is ambiguous, quote the spec section, list the options with trade-offs, recommend one, and stop. Do not proceed on assumptions that touch data model, permissions, or state machines.

## Definition of done for any task
- Matches spec section(s) cited in the task.
- Tests added and passing. Lint and static analysis clean.
- No N+1 queries on new pages. No secrets. No TODOs left without an entry in `docs/SUGGESTIONS.md`.
- Short summary: what changed, how it was verified, what is next.
