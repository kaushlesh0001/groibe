# Groibe

Groibe is a managed-delivery platform for skilled Indian professionals and mid-market clients. Groibe scopes the work, staffs it, checks it and delivers it. Open applications are a controlled add-on.

It is one Laravel app: the public site, the contributor app (`/app/contributor`), the client app (`/app/client`) and the admin panel (Filament, `/admin`).

## Where things are written down

| Need | File |
|---|---|
| What the product must do (the source of truth) | `docs/GROIBE_MASTER_SPEC.md` |
| Proposed and accepted spec changes | `docs/SPEC_CHANGE_REQUESTS.md` |
| Build passes and prompts | `docs/PROMPT_PACK.md` |
| Ideas, gaps and decisions found while building | `docs/SUGGESTIONS.md` |
| Fake data for local and staging | `docs/DEMO_DATA.md` |
| Putting the app on the shared cPanel host | `docs/DEPLOY.md` |
| Running it day to day (data requests, 2FA reset, weekly checks) | `docs/OPERATIONS.md` |
| Rules for people and AI working in this repo | `CLAUDE.md` |

If the code and the spec disagree, the spec wins. Do not edit the spec to match the code. Propose the change in `docs/SPEC_CHANGE_REQUESTS.md`.

## Stack

Laravel 13, PHP 8.3, MySQL or MariaDB. Blade, Livewire, Alpine and Tailwind. Filament for admin. Pest, Pint and Larastan for quality. Assets are built on a computer with Vite. The server never runs Node, Redis or a background process: cron runs `schedule:run` every minute and that drains the queue.

Check the installed versions with `php artisan about` and `composer show`. Do not assume them.

## Run it locally

You need PHP 8.3, Composer, Node and a database.

```bash
composer install
cp .env.example .env
php artisan key:generate
# set the DB_* values in .env, then:
php artisan migrate --seed
npm install
npm run build
composer dev
```

For fake people and projects (local and staging only, never production):

```bash
php artisan migrate:fresh --seed --seeder=DemoSeeder
```

Logins and what the demo holds are in `docs/DEMO_DATA.md`. `.env` is never committed. Real secrets live only in the host's `.env`.

## Sign in to Admin on your computer

Admin sign-in is your email and password, then a 6-digit code emailed to you. With the default `MAIL_MAILER=log` nothing is sent: open the newest file in `storage/logs` (`laravel-YYYY-MM-DD.log`) and read the code from the message "Your Groibe admin sign-in code" at the end. The code works once, for 10 minutes. There is no setting that skips it. Details: `docs/DEMO_DATA.md`. If it does not arrive on a real host: `docs/OPERATIONS.md`, section 4.

## Check your work

```bash
php -d memory_limit=1G vendor/bin/pest tests/Feature/Agreements   # one folder at a time
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

Do not run `php artisan test`: it runs out of memory. Run Pest one folder at a time. The Admin and Regression folders are slow because Filament boots slowly in tests; run them one file at a time, in the background.

## Deploy and operate

- First install, every later deploy, rollback, backups and the restore drill: `docs/DEPLOY.md`.
- Data requests, the 2FA reset and the weekly checks: `docs/OPERATIONS.md`.
- Scheduled jobs (queue, digests, housekeeping, weekly backup): listed in `docs/DEPLOY.md`, section 7.

## Contact

Questions: `contact@groibe.com`.
