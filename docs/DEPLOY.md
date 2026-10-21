# Deploying Groibe to a shared cPanel host

Status: written in Pass 10. This is a procedure, not policy. Anything marked CONFIRM depends on the host (MilesWeb at the time of writing): check it with their support or in cPanel before relying on it.

Day-to-day procedures (data requests, 2FA reset, weekly checks) are in [`docs/OPERATIONS.md`](OPERATIONS.md).

Groibe is one Laravel app. It needs PHP, MySQL, a cron job and an outbound mail server. It needs no Node, no Redis and no background process on the server (spec 17.1).

**Contents**

1. [What you need before you start](#1-what-you-need-before-you-start)
2. [PHP 8.3 and its settings](#2-php-83-and-its-settings)
3. [Where the files go (document root)](#3-where-the-files-go-document-root)
4. [First install, step by step](#4-first-install-step-by-step)
5. [The production .env](#5-the-production-env)
6. [Built assets](#6-built-assets)
7. [Cron](#7-cron)
8. [File permissions](#8-file-permissions)
9. [Private files, and the test that proves they are private](#9-private-files-and-the-test-that-proves-they-are-private)
10. [Mail: SMTP, SPF, DKIM, DMARC](#10-mail-smtp-spf-dkim-dmarc)
11. [Cloudflare](#11-cloudflare)
12. [Every later deploy](#12-every-later-deploy)
13. [Rollback](#13-rollback)
14. [Backups and the restore drill](#14-backups-and-the-restore-drill)
15. [Post-deploy smoke test](#15-post-deploy-smoke-test)
16. [Order of work, staging first](#16-order-of-work-staging-first)
17. [Things to confirm with the host](#17-things-to-confirm-with-the-host)
18. [Retention and pruning](#18-retention-and-pruning)
19. [Off-server copies](#19-off-server-copies)
20. [Uptime monitor](#20-uptime-monitor)
21. [Incidents and breaches](#21-incidents-and-breaches)
22. [Test agreement wording on staging](#22-test-agreement-wording-on-staging)

---

## 1. What you need before you start

- A cPanel account with SSH (terminal) access, Git, and Cron Jobs. CONFIRM SSH is switched on for the account.
- The domain, with its DNS under your control (at the registrar, at cPanel or at Cloudflare).
- A MySQL database and a user for it (cPanel, MySQL Databases). cPanel adds the account name in front of both names, for example `cpuser_groibe`. Give the user all privileges on that one database only.
- An SMTP mailbox on the sending domain (cPanel, Email Accounts).
- A computer with PHP 8.3, Composer and Node, to build the assets (section 6). The server never runs Node.
- Read access from the server to the Git repository. Use a read-only deploy key, not a personal password.
- The real legal texts (Member Agreement, Client MSA, SOW template, Terms, Privacy) ready to paste into Admin after the first deploy. They are never put in code.

Keep **two sites**: a staging subdomain first (its own folder, database and `.env`), then production. Section 16 gives the order.

## 2. PHP 8.3 and its settings

1. cPanel, **MultiPHP Manager**: choose PHP 8.3 for the domain. The `php` that a plain SSH login runs is often older. Find the path of the 8.3 binary (usually `/opt/cpanel/ea-php83/root/usr/bin/php`, CONFIRM) and use it for everything below, in the deploy script as `PHP_BIN`, and in cron.
2. cPanel, **Select PHP Version, Extensions**: switch on `ctype`, `curl`, `dom`, `exif`, `fileinfo`, `filter`, `gd` (with JPEG, PNG and WebP), `iconv`, `intl`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `phar`, `session`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`. Check them with `php -m` on the 8.3 binary.
3. cPanel, **MultiPHP INI Editor**:

   | Setting | Value | Why |
   |---|---|---|
   | `memory_limit` | `256M` | The app is built to stay under this (spec 17.1). |
   | `upload_max_filesize` | `26M` | Submissions may be 25 MB (spec 17.2). |
   | `post_max_size` | `30M` | Must be at least the upload size. |
   | `max_execution_time` | `60` | Imports and PDF files. |
   | `opcache.validate_timestamps` | `1` | New code is noticed after a deploy without a restart (section 12). |
   | `disable_functions` | must **not** contain `proc_open` | The scheduler starts every job as a PHP process (see below). |

4. **`proc_open` matters.** Laravel's scheduler runs each scheduled job (the queue drain, the digests, the weekly backup) as a separate PHP process. If the host disables `proc_open` for web PHP, queued email and notifications never go out and nothing is reported. Check it on the web side, not just on the command line: after the first deploy, the System status widget must show the cron as running and the queue as empty (section 15). CONFIRM with the host.

## 3. Where the files go (document root)

The app lives **outside** the web folder, so `.env`, `vendor`, `storage` and the code are never reachable from a browser. Put it beside `public_html`, for example `/home/CPUSER/groibe`.

**Option A (recommended): point the domain at `groibe/public`.**
cPanel, **Domains, Manage, Document Root**: set it to `groibe/public`. This works for a subdomain such as `staging`. For the primary domain cPanel may lock the document root to `public_html`; if so, use option B or ask the host to change it. Nothing else is needed: `public/.htaccess` already routes requests to `index.php`, and it also sends plain `http://` requests to `https://` (see "HTTP to HTTPS" below).

**Option B: copy `public` into `public_html`.**
Use this only if A is not possible.

1. After each deploy, `scripts/deploy.sh` copies `groibe/public/` into `public_html/` when you set `PUBLIC_DIR=/home/CPUSER/public_html` (it needs `rsync`). It never touches `public_html/index.php`.
2. Replace `public_html/index.php` once, so it finds the app and tells Laravel where the public folder is:

   ```php
   <?php

   use Illuminate\Http\Request;

   define('LARAVEL_START', microtime(true));

   if (file_exists($maintenance = __DIR__.'/../groibe/storage/framework/maintenance.php')) {
       require $maintenance;
   }

   require __DIR__.'/../groibe/vendor/autoload.php';

   $app = require_once __DIR__.'/../groibe/bootstrap/app.php';
   $app->usePublicPath(__DIR__);

   $app->handleRequest(Request::capture());
   ```

3. Nothing else of the app goes in `public_html`. The weekly backup command refuses to write below the public folder, so the default backup folder is safe in both options.

**HTTP to HTTPS (SCR-038).** The first rewrite rule in `public/.htaccess` redirects any request that is not HTTPS, with a 301, to the same address on `https://`.  The paths a certificate authority and cPanel AutoSSL fetch over plain HTTP (`/.well-known/acme-challenge/`, `/.well-known/pki-validation/` and `/.well-known/cpanel-dcv/`) are left out of the redirect, so issuing and renewing the certificate keeps working. It runs before PHP and covers every file, so it works on staging and on production before Cloudflare is switched on. It leaves a request alone when `X-Forwarded-Proto` is `https`, which is what Cloudflare sets, so it cannot loop. The file is committed, so option B copies it too. Do **not** also turn on the cPanel "Force HTTPS Redirect" switch: cPanel writes it into this same tracked file and `scripts/deploy.sh` would then refuse to run on uncommitted changes. It is only read by Apache (LiteSpeed also reads it); nginx and `php artisan serve` ignore it, so local work is unchanged. Rehearsed in tests, not yet run on the real host: prove it with `curl -I http://SITE/` (section 15).

**Never** run `php artisan storage:link`. The app does not use a `public/storage` link, and private files are not served that way (section 9).

## 4. First install, step by step

Do this on staging first. Replace `CPUSER`, the repository address and the paths.

1. **Database.** Create it and its user in cPanel (section 1). Write down the full names.
2. **Code.** Over SSH:

   ```bash
   cd ~
   git clone git@github.com:YOUR-ORG/groibe.git groibe
   cd groibe
   git checkout main
   ```

3. **Settings file.** `cp .env.production.example .env`, then fill in every line marked CHANGE (section 5). Use `nano .env`. Do not paste the real file anywhere else.
4. **PHP packages.** Composer must run on the PHP 8.3 binary:

   ```bash
   PHP=/opt/cpanel/ea-php83/root/usr/bin/php
   $PHP $(command -v composer) install --no-dev --optimize-autoloader --no-interaction
   ```

   If `composer` is not installed on the account, download `composer.phar` into your home folder once and use `$PHP ~/composer.phar install ...`. If the host cannot run Composer at all, run it on your computer with `--no-dev` and upload `vendor/` (slow, last resort).
5. **App key.** Once, on this server: `$PHP artisan key:generate --force`. Back the key up in your password manager. Losing it makes saved data unreadable (two-factor secrets, for one).
6. **Built assets.** Upload `public/build` (section 6).
7. **Deploy script.** It does the rest in a safe order:

   ```bash
   PHP_BIN=$PHP COMPOSER_BIN="$PHP $(command -v composer)" scripts/deploy.sh check   # only checks, changes nothing
   PHP_BIN=$PHP COMPOSER_BIN="$PHP $(command -v composer)" scripts/deploy.sh
   ```

8. **First data, once.** The seeders in `DatabaseSeeder` are the production-safe ones: roles and permissions, the super admin from `ADMIN_EMAIL` and `ADMIN_PASSWORD`, skills and project types, feature flags, agreement placeholders and the website content (stored inactive, flagged "needs review"):

   ```bash
   $PHP artisan db:seed --force
   ```

   Then open `.env`, **delete the value of `ADMIN_PASSWORD`**, and run `$PHP artisan config:cache`. The password is only read when the account is first created.
   `DemoSeeder` (fake people and projects) is refused outside the `local`, `staging` and `testing` environments. Run it on staging only if you want demo data: `$PHP artisan migrate:fresh --seed --seeder=DemoSeeder` wipes that database.
9. **Document root** (section 3), **cron** (section 7), **permissions** (section 8).
10. **Sign in** at `/admin/login` as the super admin: email and password, then a 6-digit code that is emailed to the super admin's address (SCR-040). **Mail must work first.** The code is sent at once and is never written anywhere you can read it on production, so if the email does not arrive you cannot sign in. Do step 7 of section 10 (mail) **before** this step, and on staging first. Nothing needs to be set up for the code. If you are ever locked out, the server can clear the lockout and any authenticator app: `$PHP artisan groibe:two-factor-reset someone@example.com`. The full steps, and what to do when the code email does not arrive, are in [`docs/OPERATIONS.md`](OPERATIONS.md), sections 3 and 4.
11. **Admin, once:** System, Agreement Templates: paste and publish the real legal texts. Website: approve the wording of the seeded pages and sections ("Approve wording"), then switch on what should be public. Nothing marked "needs review" is ever public.
12. Run the smoke test (section 15).

## 5. The production .env

`.env.production.example` is the template and explains each line. The ones that cause trouble:

| Setting | Rule |
|---|---|
| `APP_ENV` | `production`. `staging` on the staging site. The deploy script refuses anything else. |
| `APP_DEBUG` | `false`. A debug page prints secrets. The deploy script refuses `true`. |
| `APP_KEY` | Made on the server (step 5). Never reuse a development key. |
| `APP_URL` | The public `https://` address, no trailing slash. Links in emails and signed links use it. |
| `DB_CONNECTION` | `mysql`, even if the host runs MariaDB. The search indexes are only created for that name (SUGGESTIONS 299). |
| `DB_SIZE_LIMIT_MB` | A little under the hosting plan's real database limit. Super admins are warned at 80%. |
| `QUEUE_CONNECTION`, `SESSION_DRIVER`, `CACHE_STORE` | `database`, `database`, `file`. No Redis exists (spec 17.1). |
| `MAIL_*` | The cPanel mailbox (section 10). |
| `GROIBE_EXCEPTION_EMAIL` | Where unhandled errors are mailed, once an hour for each. Leave empty and none are sent. |
| `GROIBE_BACKUP_PATH` | A folder **outside** the web root, for example `/home/CPUSER/groibe-backups`. |
| `GROIBE_PREDEPLOY_BACKUP_KEEP` | How many pre-deploy dumps to keep (default 5). They are kept apart from the weekly ones. |
| `TRUSTED_PROXIES` | Cloudflare's address ranges (section 11). |
| `CSP_REPORT_ONLY` | `true` for the very first deploy if you want to see what the Content-Security-Policy would block without blocking it. Set it back to `false` after. |
| `ADMIN_PASSWORD` | Present for the first seed only. Delete the value afterwards. |

After any change to `.env` on a running site: `$PHP artisan config:cache`. A cached config ignores later edits to `.env`.

## 6. Built assets

The page styles and scripts (Vite) are built on a computer and uploaded. They are not in Git (`public/build` is ignored), and the deploy script stops if `public/build/manifest.json` is missing.

On your computer, from the repo, on the commit you are deploying:

```bash
npm ci
npm run build
rsync -az --delete public/build/ CPUSER@HOST:groibe/public/build/
```

No `rsync`? Zip `public/build`, upload it with cPanel File Manager or SFTP to `groibe/public/`, and unzip over the old folder. The admin panel's own assets are not uploaded: the deploy script publishes them on the server with `php artisan filament:assets`.

Upload the build **before** running `scripts/deploy.sh`, so the new pages never meet old assets. Do it for every release that changes anything in `resources/css`, `resources/js` or the views' classes.

## 7. Cron

One cron entry runs the whole schedule. In cPanel, **Cron Jobs**, add, every minute (`* * * * *`):

```
cd /home/CPUSER/groibe && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

It must use the PHP 8.3 binary and `cd` into the app first. That one line runs: the queue drain every minute (emails and notifications leave within about a minute), the overdue task digest (08:00), overdue invoices (08:15), the stale invite report (08:30), email log retention (03:00), import file clean-up (03:10), the database size check (03:20), the public cache refresh (03:30) and the weekly database backup (Sunday 02:30), and from SCR-039 the queue history clean-up (03:40), read notification clean-up (03:45), audit log clean-up (03:50) and the data request reminder (08:45). Times are in the app time zone, `Asia/Kolkata`.

Check it: `php artisan schedule:list` shows the jobs, and Admin's **System status** shows "Running (last run … ago)" within two minutes. If it says "Stopped" with a red alert line (the last run is more than 10 minutes old), or still says "No run recorded yet" after a few minutes, cron is not reaching the app: wrong PHP path, wrong folder, or `proc_open` disabled (section 2).

## 8. File permissions

- Folders 755 and files 644 is normal. `storage` and `bootstrap/cache` need to be writable by the account: the deploy script runs `chmod -R ug+rwX` on them. Never use 777.
- `storage/fonts` must be writable. The SOW PDF caches the Inter font metrics there on first use, and the PDF fails without it. The script creates the folder.
- `.env` should be readable by the account only: `chmod 600 .env`.
- Do not edit files by hand on the server. The deploy script refuses to run if tracked files have changed.

## 9. Private files, and the test that proves they are private

Brief files, submissions, message attachments, project files, import uploads, error reports and profile photos live in `storage/app/private`. That folder is outside the web root, and there is no link from `public` to it. The only way to read one is through the app: a signed link, to a signed-in person, who passes the file's policy (spec 17.2). Backups go to `GROIBE_BACKUP_PATH`, also outside the web root.

**Test it, on staging and again on production.** Replace the address.

```bash
SITE=https://staging.example.com

# 1. None of these may return 200. 403 or 404 is right. (With option A they are not even inside the document root.)
for path in /.env /.git/config /composer.json /artisan /vendor/autoload.php /storage/logs/laravel.log /storage/app/private /storage/app/public /public/storage /backups; do
  printf '%-34s %s\n' "$path" "$(curl -s -o /dev/null -w '%{http_code}' "$SITE$path")"
done

# 2. A private file cannot be fetched by guessing or by its stored path.
```

Then, by hand:

1. Sign in as a client, create a brief and attach a file.
2. On the server find the stored name: `find ~/groibe/storage/app/private -type f -newer ~/groibe/.env | head`.
3. Request `$SITE/storage/app/private/<that path>` and `$SITE/<that file name>`: both must be 404.
4. Open the file from the brief page: it downloads. Copy the link, open it in a private window where nobody is signed in: it must send you to the sign-in page. Change one letter in the link: it must be refused. Wait for it to expire (10 minutes): it must be refused.
5. Sign in as a **different** client and open the first client's link: refused.

If step 1 returns 200 for anything, **stop**: the document root is wrong (section 3).

## 10. Mail: SMTP, SPF, DKIM, DMARC

Groibe sends from the address in `MAIL_FROM_ADDRESS` through the mailbox in `MAIL_USERNAME`. Without proper DNS records, activation links and notifications land in spam, which stops people from joining.

1. Use an address on **your own domain** as the sender, made as a mailbox in cPanel. Use the host's SMTP server name and port 587 (or 465 with `MAIL_SCHEME=smtps`). CONFIRM the values in cPanel, Email Accounts, Connect Devices.
2. **SPF and DKIM.** cPanel, **Email Deliverability**, for the domain: it shows whether each record is valid and offers to install the suggested ones. If your DNS is at Cloudflare or the registrar rather than cPanel, copy the suggested records from that page into your DNS as `TXT` records. Do not write them by hand from memory: the SPF record must name the host's mail servers.
3. **DMARC.** Add a `TXT` record at `_dmarc.yourdomain`:

   ```
   v=DMARC1; p=none; rua=mailto:dmarc-reports@yourdomain; fo=1
   ```

   `p=none` only reports. After two to four weeks of clean reports, move to `p=quarantine`, and later `p=reject`. Use a mailbox someone reads for `rua`.
4. In Cloudflare, mail-related records (`MX`, `mail.` and the like) must be **DNS only** (grey cloud), never proxied.
5. **Test.** Contact form, then register a test contributor. In Gmail open the message, **Show original**, and check `SPF: PASS`, `DKIM: PASS` and `DMARC: PASS`. Also send one to a Microsoft address and check the spam folder.
6. Check the host's **hourly sending limit** (CONFIRM). Invitations are queued at 30 a minute, which is faster than many shared hosts allow per hour. Send invitations in small batches, and watch Admin, System, Email Log for failures (failed rows are kept 180 days).
7. **Send a test sign-in code before you rely on it (SCR-040).** Admin sign-in needs a 6-digit code emailed to the admin, and the code cannot be read anywhere on production. So prove the email arrives while you can still fix things:
   1. On **staging** first, with the real SMTP settings. Make sure you can still reach `.env` over SSH and that another way in exists (a second super admin, once there is one).
   2. Open `/admin/login`, enter the super admin's email and password. The page asks for a code.
   3. The email "Your Groibe admin sign-in code" must arrive **within about a minute** (it is sent at once, not through cron). Check the spam folder, and in Gmail "Show original" for `SPF: PASS`, `DKIM: PASS` and `DMARC: PASS` as in step 5.
   4. Type the code. You should be in. Try it again with the same code in a new sign-in: it must be refused.
   5. In **System, Email Log** there is a row `AdminSignInCode` with status `sent` for that address. It shows the address and the template name only, never the code.
   6. Repeat on production before anyone else relies on it. If the email does not arrive, do not carry on: fix the mail settings first (steps 1 to 6 above). What to do when it does not arrive is in [`docs/OPERATIONS.md`](OPERATIONS.md), section 4.
   Do **not** switch production to `MAIL_MAILER=log` to read a code: that writes every sign-in code into a log file. Local use is different: on your own computer `MAIL_MAILER=log` puts the email in the newest file in `storage/logs` (README).

## 11. Cloudflare

Put the site behind Cloudflare once DNS, mail and the smoke test are working.

| Setting | Value |
|---|---|
| DNS record for the site | Proxied (orange cloud). |
| SSL/TLS mode | **Full (strict)**. Install a valid certificate on the host first (cPanel AutoSSL). Never "Flexible": it causes redirect loops and sends traffic to the host in clear. |
| Always Use HTTPS | On. Keep it on even though `public/.htaccess` has its own redirect (section 3, SCR-038): the host rule covers staging and the time before Cloudflare, Cloudflare's covers the edge. The two cannot loop because the host rule honours `X-Forwarded-Proto: https`. The app also sends HSTS on HTTPS. |
| Rocket Loader, Auto Minify (JavaScript), and any automatic script or analytics injection | **Off.** The public pages send a strict Content-Security-Policy with no inline script, and anything Cloudflare injects is blocked and breaks the page. |
| Caching | Standard. Do **not** use "Cache Everything" on pages: pages carry forms and sign-in state. Static files (`/build`, `/brand`, `/fonts`, `/css`, `/js`) are cached by default. |
| Cache bypass rules | `/admin*`, `/app*`, `/livewire*`, `/login`, `/register*`, `/invite*`, `/files*`, `/notifications*`, `/settings*`. |
| WAF | Managed rules on. A rate-limit rule on `/login` and `/forgot-password` is a good second layer; the app already limits them (spec 17.2). |

**Real visitor addresses.** The app takes the visitor's address from `X-Forwarded-For` for rate limits and stored acceptance records, and only trusts it from addresses listed in `TRUSTED_PROXIES`. Put Cloudflare's published ranges there (`https://www.cloudflare.com/ips-v4` and `/ips-v6`), comma separated. They change rarely; re-check them twice a year and after any Cloudflare announcement. `TRUSTED_PROXIES=*` trusts every sender, so a person who finds the server's own address can pretend to be anyone: use it only if the host accepts web traffic from Cloudflare alone.

## 12. Every later deploy

1. On your computer: merge to `main`, tag the release (`git tag release-2026-11-03 && git push --tags`), build and **upload the assets** (section 6).
2. Over SSH:

   ```bash
   cd ~/groibe
   PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php COMPOSER_BIN="/opt/cpanel/ea-php83/root/usr/bin/php $(command -v composer)" scripts/deploy.sh
   ```

   It: checks the setup, **takes a pre-deploy database backup** (see below), records the current release, puts the site in maintenance mode, pulls `main` (fast-forward only), installs packages without dev tools, runs migrations, publishes admin assets, creates and secures the folders, rebuilds the caches (`config:cache`, `route:cache`, `view:cache` and `event:cache`, after clearing the old ones), restarts queue workers, brings the site back, and calls `/up`.
3. **The pre-deploy backup (SCR-037).** Before anything changes, and before the site goes into maintenance mode, the script runs `php artisan groibe:backup-db --predeploy`. That writes `groibe-predeploy-YYYYMMDD-HHMMSS.sql.gz` into `GROIBE_BACKUP_PATH`. The newest `GROIBE_PREDEPLOY_BACKUP_KEEP` (default 5) are kept, apart from the weekly dumps, so frequent deploys never push out the weekly ones. The script **stops, with the site still up and nothing changed**, if the command fails, or if the file is missing, empty or smaller than 4 KB (it does not trust the exit code alone). It also runs `gzip -t` on the file, so a dump that is cut off or damaged stops the deploy too. On a first deploy the database has no tables, there is nothing to dump, and the script says the backup is skipped. There is no switch to skip it: if the disk is full, free space first. Rehearsed with a stand-in command in tests, not yet run on the real host: watch for the line "Pre-deploy backup written" on your first staging deploy.
4. If it stops part way, **the site stays in maintenance mode on purpose**: the code and the database may not match. Read the error, fix it, run the script again, or roll back (section 13).
5. Run the smoke test (section 15).

**After a deploy.** `scripts/deploy.sh` clears the command-line opcache only. The web server keeps its own, and no command can reach it. With `opcache.validate_timestamps = 1` (section 2) it notices the new files within a few seconds by itself. If a page still behaves as the old release a minute later, ask the host to restart PHP for the account, or toggle the PHP version once in MultiPHP Manager.

Options for the script, as environment variables: `PHP_BIN`, `COMPOSER_BIN`, `DEPLOY_BRANCH` (default `main`), `DEPLOY_REMOTE` (default `origin`) and `PUBLIC_DIR` (option B only). Run `scripts/deploy.sh check` to run only the safety checks.

## 13. Rollback

**Code only** (nothing in the database changed, or the old code still works with it):

```bash
cd ~/groibe
PHP_BIN=... COMPOSER_BIN=... scripts/deploy.sh rollback
```

It goes back to the commit recorded before the last deploy, reinstalls its packages, rebuilds the caches and brings the site up. It does not undo migrations. The next normal deploy returns to `main` by itself. Upload the matching `public/build` first if the assets differ.

**Code and database.** If the bad release added or changed tables and the old code cannot run on them: roll the code back as above, put the site in maintenance mode (`php artisan down`), restore the last good dump (section 14), run `php artisan up`. You lose what was saved after that dump. Every deploy already wrote a `groibe-predeploy-*` dump just before it began (section 12), so the newest one is the state the release started from: use it, not the weekly dump.

Migrations are forward-only here. Do not run `migrate:rollback` on production.

## 14. Backups and the restore drill

Two copies exist: the host's own daily backups, and the weekly dump from `php artisan groibe:backup-db` (Sunday 02:30, the newest `GROIBE_BACKUP_KEEP` are kept).  A third kind, `groibe-predeploy-*`, is written by `scripts/deploy.sh` before every release (section 12) and is kept apart (newest `GROIBE_PREDEPLOY_BACKUP_KEEP`). The restore drill below works with any of them. The dump is a gzipped SQL file in `GROIBE_BACKUP_PATH`. It holds personal data and is written with owner-only permissions. It leaves out the rows of `sessions`, `cache` and password-reset tokens, so after a restore everyone has to sign in again.

Both copies sit on the same server. **Download the dump off the server** regularly (SFTP to a safe place), and also take a copy of `.env` and `APP_KEY` into the password manager. Files in `storage/app/private` are **not** in the dump: they are covered by the host's backups only, so download `storage/app/private` too, at least monthly.

**Restore drill: do it on staging before go-live, then every quarter.** It proves the dump works.

1. Take a fresh dump: `php artisan groibe:backup-db`. Note the file name it prints.
2. In cPanel, MySQL Databases, make a **scratch** database and a user for it (`cpuser_scratch`). Never use the live database for a drill.
3. Restore into it. The password is asked for, never typed on the command line:

   ```bash
   gunzip -c ~/groibe-backups/groibe-db-YYYYMMDD-HHMMSS.sql.gz | mysql -u cpuser_scratchuser -p cpuser_scratch
   ```

4. Check it: `mysql -u cpuser_scratchuser -p cpuser_scratch -e "SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM projects; SELECT COUNT(*) FROM migrations;"`. The counts must match the live site (a little lower is fine if people signed up since the dump).
5. Optionally point a copy of the app at it: a throwaway `.env` with `DB_DATABASE=cpuser_scratch`, then `php artisan migrate --force --pretend` must report nothing to run.
6. Delete the scratch database and its user. Write the date and the result in the team's log.

**A real restore** is the same command into the real database, with the site in maintenance mode, after a fresh dump of the broken state is taken and kept. `mysql` replaces each table (`DROP TABLE IF EXISTS`), so it overwrites everything it names.

## 15. Post-deploy smoke test

Run this after the first deploy and after every release. Tick each line.

**The site**
- [ ] `curl -s -o /dev/null -w '%{http_code}' https://SITE/up` returns 200.
- [ ] `https://SITE/` opens over HTTPS, in a private window, with no red errors in the browser console (a Content-Security-Policy error means something is injecting scripts: section 11).
- [ ] `curl -sI http://SITE/ | head -n 3` shows `301` and a `Location: https://SITE/` line, on staging (no Cloudflare) and on production. `curl -sI -H "X-Forwarded-Proto: https" http://SITE/` does **not** show a redirect to https (no loop).
- [ ] Headers: `curl -sI https://SITE/` shows `strict-transport-security`, `x-frame-options: DENY`, `x-content-type-options: nosniff`, `referrer-policy` and a `content-security-policy`.
- [ ] A page that does not exist shows the 404 page, not a stack trace.
- [ ] `/sitemap.xml` and `/robots.txt` open. Private people and organizations are not in the sitemap.
- [ ] The private-file test in section 9 passes.
- [ ] Session cookie is `Secure`, `HttpOnly`, `SameSite=Lax` (browser dev tools, Application, Cookies).

**Sign-in and admin**
- [ ] `/admin/login` asks the super admin for a 6-digit code after the password; the email arrives within a minute; a wrong code is refused; the right code lets them in; the same code does not work a second time (SCR-040).
- [ ] System, Email Log shows a row with template `AdminSignInCode` and status `sent` for that address, and no row shows a code.
- [ ] System status shows cron **Running**, 0 jobs waiting, 0 failed jobs, and a database size line (it shows "Not checked yet" until 03:20 or `php artisan groibe:check-database-size`).
- [ ] An open Admin page for a delivery manager does not show System status.
- [ ] `/login` is rate limited: six wrong passwords in a minute are refused.

**Email and queue**
- [ ] Contact form: the message arrives at `GROIBE_CONTACT_EMAIL` within two minutes, and shows under Website, Contact Messages.
- [ ] Register a test contributor: the verification email arrives, not in spam, with SPF, DKIM and DMARC passing (section 10).
- [ ] The visitor address is recorded correctly. After sending the contact form from your own computer, run `php artisan tinker --execute="echo \App\Models\ContactMessage::latest('id')->value('ip');"` on the server. It must be **your** address (compare with a "what is my IP" page), not a Cloudflare one. A Cloudflare address means `TRUSTED_PROXIES` is wrong, and every visitor would share one rate limit (section 11).
- [ ] Admin, System, Email Log lists those emails as `sent`.
- [ ] `php artisan tinker --execute="report(new Exception('Deploy test'));"` (on the server) sends one email to `GROIBE_EXCEPTION_EMAIL`. Running it again within the hour sends none.

**Files, PDF and imports**
- [ ] A SOW opened as a PDF downloads and is set in Inter (needs `storage/fonts` writable).
- [ ] A contributor photo uploads and shows.
- [ ] Import a small sample CSV: the check runs, the report downloads, nothing is created until you start it.

**Operations**
- [ ] `php artisan groibe:backup-db` writes a file into `GROIBE_BACKUP_PATH` and not into the web root, and the restore drill (section 14) has been done once.
- [ ] A deploy printed "Pre-deploy backup written: groibe-predeploy-…" before it went into maintenance mode, and the file is in `GROIBE_BACKUP_PATH` (SCR-037). The first deploy to a database with no tables says the backup is skipped.
- [ ] `storage/logs/laravel-DATE.log` is being written, and it contains no passwords or tokens.
- [ ] `php artisan schedule:list` shows fourteen jobs.
- [ ] On production, the test agreement wording check in section 22 prints `0`.
- [ ] The uptime monitor (section 20) is on and its alert email arrives.
- [ ] The first encrypted off-server copy (section 19) has been made and opened once.

## 16. Order of work, staging first

1. Staging subdomain on the host: its own folder, database, `.env` (`APP_ENV=staging`) and mailbox. Install (section 4). Run the smoke test.
2. Confirm cron is running (System status), send test emails, check spam placement, fix SPF, DKIM and DMARC.
3. Do the restore drill on a scratch database.
4. Paste the real legal templates into Admin and publish them. Approve the website wording. On a staging site without demo data, publish the test wording of section 22 first.
5. Import contributors and clients on staging. Read the error report. Fix the file.
6. Production: same steps, `APP_ENV=production`, then the Cloudflare settings (section 11).
7. Import to production. Send invitations in **small batches**, watching the Email Log.
8. Seed the first real open roles, and approve the first proof-of-work items, before opening public sign-up.

## 17. Things to confirm with the host

These cannot be checked from the code. Ask once, in writing, and keep the answers with this file.

| Question | Why it matters |
|---|---|
| PHP 8.3 with `gd` (JPEG, PNG, WebP), `intl`, `zip` and `pdo_mysql` available? | The app and the photo upload need them. |
| Is `proc_open` allowed for web PHP and for cron? | The scheduler needs it. Without it no queued email is ever sent. |
| Can a cron job run every minute? Is SSH on? | The queue is drained by cron (spec 17.1). |
| The real database size limit, the maximum connections, and the file quota (inodes and disk). | `DB_SIZE_LIMIT_MB`; four weekly dumps also take disk. |
| The hourly and daily outgoing email limit. | Invitations and notifications are throttled by the host, not by Groibe. |
| Can the document root of the domain be set to `groibe/public`? | Option A in section 3. |
| Is the connection MySQL or MariaDB, and its version? | Use `DB_CONNECTION=mysql` either way. FULLTEXT search needs InnoDB. |
| What do the host's daily backups cover, and for how long? | The dump does not hold files in `storage/app/private`. |

## 18. Retention and pruning

Data that grows without limit eats the host's database quota (`DB_SIZE_LIMIT_MB`) and keeps personal data longer than needed. These are the rules, and the app applies all of them (SCR-039 added the last four). The days are settings: a super admin changes one in **System, Settings** by adding a row with the key shown. A missing row, a value that is not a whole number, or a value below the minimum falls back to the default, so a typo cannot switch pruning off or wipe the audit trail. The minimums are 7 days for `failed_jobs` and `job_batches`, 30 for read notifications and 90 for the audit log; the Settings screen says so under the Value field (SCR-039 amendment). Use type Integer.

| Data | Keep | Status |
|---|---|---|
| Email log, `sent` rows | 90 days (daily 03:00, SCR-032) | Built |
| Email log, `failed` rows | 180 days | Built |
| Import files and their reports | 30 days after upload; temporary uploads 1 day (daily 03:10, SCR-034) | Built |
| Stale invites | Reported after 14 days, never deleted (daily 08:30, SCR-034) | Built |
| Expired password-reset tokens | Cleared daily (SCR-034) | Built |
| Log files (`storage/logs`) | 14 days (`daily` channel) | Built |
| Weekly database dumps | Newest `GROIBE_BACKUP_KEEP` (default 4) | Built |
| `failed_jobs` (by `failed_at`) | 30 days (at least 7), setting `retention.failed_jobs_days` (daily 03:40, `groibe:prune-queue-history`) | Built |
| `job_batches` (by `created_at`) | 30 days (at least 7), setting `retention.job_batches_days` (same job) | Built |
| Notifications that have been read (by `created_at`) | 180 days (at least 30), setting `retention.notifications_read_days` (daily 03:45, `groibe:prune-notifications`). Unread ones are never deleted. | Built |
| `activity_log` (audit trail) | 365 days (at least 90), setting `retention.activity_log_days` (daily 03:50, `groibe:prune-activity-log`, which runs `activitylog:clean`) | Built |

Each prune job writes the number removed to the audit log (even when it is none). Payouts, agreement acceptances and the audit entries of erasures follow SCR-028, not this table. If the lawyer sets a longer period for the audit trail or the email log, change this table and the spec first.

## 19. Off-server copies

The host's backups and the weekly dump sit on the same server (section 14). One bad day can take both. So a second copy leaves the server every week.

**Owner: the founder. When: every week, after Sunday 02:30.** The dump holds personal data and is **not encrypted by the app**. It must be encrypted **before it leaves the server**.

1. SSH in. Go to the backup folder (`GROIBE_BACKUP_PATH`) and encrypt the newest dump with a passphrase:

   ```bash
   cd ~/groibe-backups
   gpg --symmetric --cipher-algo AES256 groibe-db-YYYYMMDD-HHMMSS.sql.gz
   ```

   It asks for the passphrase twice and writes `groibe-db-YYYYMMDD-HHMMSS.sql.gz.gpg`. The passphrase lives in the password manager. Never type it into a script or a file on the server.
2. Download the `.gpg` file by SFTP to a safe place that is not the server and not an open shared folder.
3. Delete the `.gpg` file from the server. The plain dump stays under the normal retention.
4. **Monthly:** do the same for `storage/app/private` (it is not in the dump):

   ```bash
   cd ~/groibe/storage/app
   tar -czf - private | gpg --symmetric --cipher-algo AES256 -o ~/groibe-backups/private-YYYYMMDD.tar.gz.gpg
   ```

   Download it, then delete it from the server.
5. Keep `.env` and `APP_KEY` in the password manager too (section 14). Without `APP_KEY`, encrypted columns in a restored database cannot be read.
6. Once a quarter, decrypt one copy and run the restore drill (section 14) from it, so you know the passphrase and the file both work.

How many off-server copies to keep is the founder's choice. A starting value is the last 8 weekly dumps and the last 3 monthly file copies.

## 20. Uptime monitor

Set up an outside monitor on `https://SITE/up` (Laravel's health route). Any monitoring service that checks a URL every 5 minutes and sends **email alerts** will do. Send the alerts to the founder and to `contact@groibe.com`. Do this for production, and for staging while it is in use.

- Alert when the check fails twice in a row.
- `/up` shows that the app starts. It does **not** show that cron runs, so a stopped cron raises no email alert. Instead, Admin's **System status** shows a red "Alert: cron has not run for N minutes" line once the cron heartbeat is more than 10 minutes old (SCR-039). It is only seen when a super admin opens the dashboard, so keep the weekly check in `docs/OPERATIONS.md`. An email alert for a stopped cron is not built.
- Write down the monitor's account name and who can see it, next to the host answers in section 17.

## 21. Incidents and breaches

These are short checklists. They are not legal advice. **If personal data may have been lost, shown to the wrong person or taken, get legal advice before you tell anyone outside the company, and before you decide whether and when someone must be told. Do not guess at deadlines or duties.**

**Any incident (site down, errors, wrong behaviour)**

1. Note the time, what you saw and who reported it. Write it down as you go.
2. Check the uptime monitor, System status (cron, failed jobs, database size) and the newest `storage/logs` file.
3. If a bad release caused it, roll back the code (section 13).
4. If the site must be closed while you work, run `php artisan down`. Bring it back with `php artisan up`.
5. Fix it, then run the smoke test (section 15).
6. Write down the cause and what changed, and add it to SUGGESTIONS if code needs a fix.

**Possible breach (data seen or taken by someone who should not have it)**

1. Stop the damage first: sign the account out and suspend it, reset the password, reset 2FA where needed (`docs/OPERATIONS.md`), and rotate any secret that may have leaked (database password, mail password). Rotate `APP_KEY` only with care, because it affects encrypted data.
2. **Keep the evidence.** Do not delete logs. Copy `storage/logs`, the audit log and the email log somewhere safe.
3. Write down: what data, how many people, from when to when, how it happened, and what has been stopped.
4. **Get legal advice now.** Ask what must be done, by when, and who must be told.
5. Tell the host's support if the server itself may be affected.
6. After it is settled, write down what will change so it cannot happen the same way again.

## 22. Test agreement wording on staging

Until real legal wording is published, the Member Agreement, Client MSA and SOW template count as not published (SCR-036). On a staging site **without demo data**, contributors cannot finish onboarding, client owners cannot submit a brief and no SOW can be built. (With demo data, `DemoSeeder` publishes its own labelled wording.) For staging only, publish this test wording.

1. Sign in as super admin on **staging**. Open **System, Agreement Templates**.
2. For each of the three templates, press **Revise** on its row (or **Publish agreement version** if the list is empty). Replace the body with the banner below, then a line of dummy text. Saving publishes the next version and retires the old one. For the SOW template keep the variables from spec 8 (`{{client_name}}`, `{{project_title}}`, `{{scope}}`, `{{milestones_table}}`, `{{total_value}}`, `{{payment_terms}}`, `{{start_date}}`, `{{due_date}}`).
3. **Do not** keep the sentence "Replace with the approved legal text." in the body: while it is there the template still counts as a placeholder.
4. Check the list: the Wording column must say "Real wording" for the three, and the page that opened after saving shows the new version.

Banner, as the first line of every test body (it is shown to the person who accepts):

```html
<p><strong>TEST TEXT, NOT LEGAL WORDING. This is a staging site. Nothing here is a contract.</strong></p>
```

Terms and Privacy stay as placeholders on staging, so `/terms` and `/privacy` stay 404.

**Check that test wording is never live on production.** Do it before go-live, after the real wording is published, and after every deploy:

```bash
php artisan tinker --execute="echo App\Models\AgreementTemplate::query()->where('is_active', true)->where('body_html', 'like', '%TEST TEXT, NOT LEGAL WORDING%')->count();"
```

It must print `0`. If it prints more than `0` on production, replace that template with the approved wording at once and ask the lawyer whether anyone accepted it. This check is also in the smoke test (section 15).

The app also guards this (SCR-039). On production, publishing a version whose text holds the banner is refused, with a message on the text field, and nothing is saved. If test wording is already live on production, **System status** shows a red "Alert: test wording is live on the site" line with the template names. The manual check above stays as the proof before go-live.
