# Staging checklist: first deploy to staging.groibe.com

Status: a procedure for a beginner. It is not policy. Nothing in this file has been run on the real server yet. The deeper reference is [`docs/DEPLOY.md`](DEPLOY.md). Day-to-day work is in [`docs/OPERATIONS.md`](OPERATIONS.md).

**How to read each step.** Every step says where you do it, what to do, and what success looks like. Tick the box only when you see the success line. If you do not see it, stop and read the "If it fails" line. Do not skip ahead.

**Where.** These labels are used in every step:

| Label | Meaning |
|---|---|
| PC | PowerShell on your own computer, in `C:\Users\Kaushlesh\Herd\groibe` unless a step says otherwise |
| SSH | The server, over SSH, as `groibe_c1` |
| cPanel | The MilesWeb cPanel in your browser |
| Cloudflare | The Cloudflare dashboard in your browser |
| GitHub | github.com, repository `kaushlesh0001/groibe` |
| Browser | A normal browser window on the staging site |

**Facts this checklist is built on** (yours, not checked by me):

- Host: MilesWeb shared hosting with cPanel. SSH: user `groibe_c1`, host `45.199.139.16`, port 22.
- PHP 8.3.33 is at `/opt/ecp-php83/bin/php`. Composer 2.10, Git 2.43. Symlinks work.
- Two databases on `localhost` (MariaDB 11.4), each with **its own user**, made in cPanel: `groibe_c1_groibe` is **production** and `groibe_c1_staging` is **staging** (decision D1). Staging never uses the production database.
- `groibe.com` runs a **live WordPress site**. It must not be touched until the cutover (Part 17).
- DNS is on Cloudflare. The GitHub repository is private.

**No password goes in this file.** When a step needs a password, you type it at a prompt, or you keep it in your password manager.

---

## What could affect the live WordPress site

Read this table first. Everything not listed here is on the new `staging.groibe.com` only.

| Step | Risk to WordPress | What to do |
|---|---|---|
| 4: create the subdomain | cPanel suggests a document root **inside** `public_html`, which is WordPress's folder | Type `groibe-staging/public`. Never accept the suggested one. |
| 4: PHP version and settings | Picking the wrong place changes PHP for the whole account | Choose `staging.groibe.com` only. Never "Home directory". |
| 5: Cloudflare DNS | A wrong edit changes the live site | Add **one new** record. Do not edit or delete any existing record. |
| 5 and 6: Cloudflare settings | SSL mode, Always Use HTTPS, Rocket Loader and Auto Minify apply to the **whole** `groibe.com` zone | Do not change any zone setting before the cutover. |
| 7: password protection | Protecting the wrong folder locks visitors out of WordPress | Protect `groibe-staging` only. Never `public_html`. |
| 13: mail DNS (SPF, DKIM, DMARC) | These are on `groibe.com`, so they also cover WordPress and your real company email | Read the existing records first. Change the one SPF record. Never add a second. |
| 13: Email Routing | Changing it can break incoming company email | Do not open or change it. |
| 17: cutover | This is the step that replaces WordPress | Only on cutover day, with the backup in hand. |
| Option B in DEPLOY.md section 3 | It copies files **into `public_html`** and would overwrite WordPress files such as `.htaccess` | Never use option B on this account. |

---

## Mismatches between DEPLOY.md and your facts

DEPLOY.md was corrected on 2026-10-22 for items 1 (the PHP path, in DEPLOY.md only), 4, 5 and 6, and it now points here for the first deploy. Items 2, 3, 7, 8 and 10 are still as written. OPERATIONS and `scripts/deploy.sh` still show the old PHP path (SUGGESTIONS 350). Where this checklist and DEPLOY.md differ, this checklist wins.

| # | DEPLOY.md says | Your facts | Effect if you follow DEPLOY.md |
|---|---|---|---|
| 1 | PHP is `/opt/cpanel/ea-php83/root/usr/bin/php` (sections 2, 4, 7, 12, 13; OPERATIONS 3 and 4; the header of `scripts/deploy.sh`) | `/opt/ecp-php83/bin/php` | **The cron line would never run** and queued mail would never be sent. This checklist uses your path. |
| 2 | The app folder is `~/groibe`, for staging and for production | Staging and production need two folders | They would collide. This checklist uses `~/groibe-staging` for staging and keeps `~/groibe` for production. |
| 3 | Clone from `git@github.com:YOUR-ORG/groibe.git` | `kaushlesh0001/groibe`, private | Part 3 uses a deploy key and an SSH alias. |
| 4 | Upload `public/build` with `rsync` from your computer (section 6) | You are on Windows PowerShell, which has no `rsync` | Part 9 uses `tar` and `scp`, which Windows has. |
| 5 | Option A can fail for the primary domain, then use option B (section 3) | `groibe.com` is a live WordPress site in `public_html` | Option B would overwrite WordPress. Part 17 uses option A only, or the host's help. |
| 6 | "Do **not** also turn on the cPanel Force HTTPS switch" because cPanel writes into the tracked `public/.htaccess` and the deploy script then refuses | The same is true of **Directory Privacy** (HTTP auth), which DEPLOY.md does not mention | If you protect `public`, `scripts/deploy.sh` stops with "uncommitted changes". Part 7 protects the parent folder instead. |
| 7 | The health check and uptime monitor call `/up` | On staging, HTTP auth answers `401` | The deploy script prints a warning about the health check. That is expected on staging (Part 11). |
| 8 | Mail: "use an address on your own domain" and add SPF, DKIM, DMARC | `groibe.com` also carries WordPress and, probably, your real company email | See the risk table. Part 13 checks where the mail lives first. |
| 9 | Staging database "its own database" (section 1, 16) | Staging is `groibe_c1_staging`, production is `groibe_c1_groibe`, each with its own user | Matches DEPLOY.md. See decision D1 below. |
| 10 | `scripts/deploy.sh` is run as `scripts/deploy.sh` | The file is executable in Git, so this works, but a copy through Windows can lose that | This checklist runs `bash scripts/deploy.sh`, which always works. |

Items that **do** match: 14 scheduled jobs (`php artisan schedule:list` shows 14), FULLTEXT needs InnoDB and `DB_CONNECTION=mysql`, HTTPS rule in `public/.htaccess`, symlinks are not needed (the app never uses `storage:link`).

## Decisions (D1 to D3, all made)

Decided by the founder on 2026-10-22:

- **D1. Databases. DECIDED.** Production is `groibe_c1_groibe`. Staging is `groibe_c1_staging`. Each has its own database user, with **All Privileges on its own database only**. Staging has its own folder (`~/groibe-staging`) and its own `.env`. Production will have its own folder (`~/groibe`) and its own `.env`. Staging test data never reaches production, and a mistake on staging cannot touch production data. Part 8.1 and Part 10.1 each have a check that staging does not point at the production database. The WordPress database is a third database and is never used by Groibe.
- **D2. Mail for `groibe.com`. DECIDED.** Email for `groibe.com` stays at MilesWeb. The mail records in Cloudflare (`MX`, `mail`, and any other record used for mail) are set to **grey (DNS only)**, never proxied. Part 13.1 still reads the live records, to confirm the answer before anything is changed.
- **D3. The HTTP login for staging. DECIDED.** The login name is a non-obvious name of your choice (not `admin`, `staging`, `groibe` or `test`). The password is long and random and comes from your password manager. **Neither the name nor the password is ever written in the repository**, in this file, in `.env`, in a commit or in a chat message. In the steps below it appears as `STAGING-LOGIN`.

---

## Part 1. Your computer: push, build, pack

**1.1 Check the code is clean and push it.**

- Where: PC
- Do:

  ```powershell
  cd C:\Users\Kaushlesh\Herd\groibe
  git status --short
  git log -1 --oneline
  git push origin main
  git push origin staging-ready-2
  ```

  Push the one tag by name. Do not use `git push --tags`.
- Success: `git status --short` prints nothing. The push ends with `main -> main` and `[new tag] staging-ready-2 -> staging-ready-2`. Write down the short commit code from `git log -1 --oneline`. You will compare it on the server in Part 3.
- If it fails: a push error about login means GitHub needs your sign-in. Fix that first.
- [ ] Done

**1.2 Build the page styles and scripts.** They are not in Git. The server never runs Node.

- Where: PC
- Do:

  ```powershell
  npm ci
  npm run build
  Test-Path public\build\manifest.json
  ```
- Success: the build ends without errors and the last line prints `True`.
- If it fails: read the first red error. Do not carry on with an old `public\build`.
- [ ] Done

**1.3 Pack the build into one file.**

- Where: PC
- Do:

  ```powershell
  tar -czf "$env:TEMP\groibe-build.tgz" -C public build
  (Get-Item "$env:TEMP\groibe-build.tgz").Length
  ```
- Success: a number above 100000 prints (bytes).
- [ ] Done

---

## Part 2. The server: check what is there

**2.1 Log in and look around (read only).**

- Where: PC, then SSH
- Do:

  ```powershell
  ssh -p 22 groibe_c1@45.199.139.16
  ```

  The first time, it asks "Are you sure you want to continue connecting". Type `yes`. Then, on the server:

  ```bash
  pwd
  export PHP=/opt/ecp-php83/bin/php
  echo 'export PHP=/opt/ecp-php83/bin/php' >> ~/.bashrc
  $PHP -v | head -1
  composer --version
  git --version
  ls ~/public_html | head
  ```
- Success: `pwd` prints `/home/groibe_c1`. PHP prints `PHP 8.3.33`. Composer prints `2.10`. Git prints `2.43`. `ls ~/public_html` lists the WordPress files (`wp-config.php`, `wp-content`). **Only look. Do not change anything in `public_html`.**
- If `pwd` is not `/home/groibe_c1`, use the real path wherever this file says `/home/groibe_c1`.
- [ ] Done

**2.2 Check the PHP extensions and `proc_open`.**

- Where: SSH
- Do:

  ```bash
  for e in ctype curl dom exif fileinfo filter gd iconv intl json mbstring openssl pdo_mysql phar session simplexml tokenizer xml xmlreader xmlwriter zip zlib; do $PHP -m | grep -qix "$e" || echo "MISSING: $e"; done
  $PHP -r 'var_dump(function_exists("proc_open"));'
  $PHP -r 'print_r(gd_info());' | grep -i -E "jpeg|png|webp"
  mysql --version
  ```
- Success: no `MISSING:` line, `bool(true)`, JPEG, PNG and WebP each show `1` or `1`-like true, and `mysql --version` prints a MariaDB 11.4 line.
- If an extension is missing, turn it on in Part 4.4. If `proc_open` is `false`, stop: the scheduler cannot work. Ask MilesWeb to enable it. If `mysql` is not found, try `mariadb --version`, and use `mariadb` wherever this file says `mysql`.
- [ ] Done

---

## Part 3. The server: deploy key and clone

The repository is private. The server gets a **read-only deploy key**, never your GitHub password.

**3.1 Make the key.**

- Where: SSH
- Do:

  ```bash
  mkdir -p ~/.ssh && chmod 700 ~/.ssh
  ssh-keygen -t ed25519 -f ~/.ssh/groibe_deploy -C "groibe deploy key" -N ""
  cat ~/.ssh/groibe_deploy.pub
  ```
- Success: the last command prints one line starting with `ssh-ed25519`. Select and copy that whole line. Never copy the file without `.pub`.
- [ ] Done

**3.2 Give GitHub the public key.**

- Where: GitHub
- Do: open the repository, **Settings**, **Deploy keys**, **Add deploy key**. Title: `MilesWeb server`. Paste the line. Leave **Allow write access UNTICKED**. Press **Add key**.
- Success: the key is listed and does not say it has write access.
- [ ] Done

**3.3 Tell the server to use that key for GitHub.**

- Where: SSH
- Do:

  ```bash
  cat >> ~/.ssh/config <<'EOF'
  Host github-groibe
    HostName github.com
    User git
    IdentityFile ~/.ssh/groibe_deploy
    IdentitiesOnly yes
  EOF
  chmod 600 ~/.ssh/config
  ssh -T git@github-groibe
  ```

  It shows a fingerprint and asks to continue. Compare it with the ed25519 fingerprint on GitHub's page "GitHub's SSH key fingerprints" (docs.github.com). It should read `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`. If it matches, type `yes`.
- Success: `Hi kaushlesh0001/groibe! You've successfully authenticated, but GitHub does not provide shell access.`
- If it fails: `Permission denied (publickey)` means the key was not added (3.2) or was pasted with a line break.
- [ ] Done

**3.4 Clone into the staging folder.**

- Where: SSH
- Do:

  ```bash
  cd ~
  git clone git@github-groibe:kaushlesh0001/groibe.git groibe-staging
  cd groibe-staging
  git checkout main
  git log -1 --oneline
  ```
- Success: the commit code printed is the same as the one you wrote down in 1.1.
- If it differs: you did not push, or pushed a different branch. Go back to Part 1.
- [ ] Done

---

## Part 4. cPanel: the subdomain, its PHP and its settings

**4.1 Make the subdomain with its own document root.**

- Where: cPanel, **Domains**, **Create A New Domain**. (On an older cPanel: **Subdomains**.)
- Do: Domain `staging.groibe.com`. **Untick "Share document root".** Document Root: `groibe-staging/public`. Do not accept the suggested `public_html/...` value. Leave "Force HTTPS Redirect" **off**. Submit.
- Success: in the Domains list, `staging.groibe.com` shows the document root `/home/groibe_c1/groibe-staging/public`. The `groibe.com` row is unchanged and still points at `public_html`.
- [ ] Done

**4.2 Set PHP 8.3 for the subdomain only.**

- Where: cPanel, **MultiPHP Manager** (or **Select PHP Version** on this host)
- Do: tick only `staging.groibe.com`. Choose PHP 8.3. Apply. Do not tick `groibe.com`.
- Success: the `staging.groibe.com` row shows 8.3. The `groibe.com` row shows what it showed before.
- [ ] Done

**4.3 Set the PHP limits for the subdomain only.**

- Where: cPanel, **MultiPHP INI Editor**, **Basic Mode**
- Do: in the location list choose `staging.groibe.com`. **Never choose "Home directory".** Set `memory_limit` 256M, `upload_max_filesize` 26M, `post_max_size` 30M, `max_execution_time` 60. Apply. In **Editor Mode**, for the same location, check that `disable_functions` does not contain `proc_open`.
- Success: the page shows the new values for `staging.groibe.com`.
- [ ] Done

**4.4 Turn on any missing extensions (only if 2.2 listed some).**

- Where: cPanel, **Select PHP Version**, **Extensions**, for 8.3
- Do: tick each missing name. Save. Run the loop in 2.2 again.
- Success: the loop prints nothing.
- [ ] Done

---

## Part 5. Cloudflare: one new DNS record

**5.1 Find the web address of the server.**

- Where: cPanel home page, right side, **Shared IP Address**
- Success: you have an IPv4 address. It is normally `45.199.139.16`. If it is different, use the cPanel one for DNS (the SSH address and the web address can differ on some hosts).
- [ ] Done

**5.2 Add the record.**

- Where: Cloudflare, `groibe.com`, **DNS**, **Records**, **Add record**
- Do: Type `A`. Name `staging`. IPv4 address: the one from 5.1. Proxy status: **DNS only (grey cloud)**. TTL Auto. Save. **Do not edit or delete any other record.**
- Success: the new row shows a **grey** cloud.
- [ ] Done

**5.3 Check the name resolves to your server.**

- Where: PC
- Do: `Resolve-DnsName staging.groibe.com -Type A`
- Success: it prints your server address from 5.1. If it prints an address starting `104.` or `172.67.`, the cloud is orange. Click it to turn it grey. If it says the name does not exist, wait two minutes and try again.
- [ ] Done

---

## Part 6. cPanel and PC: certificate and HTTPS redirect

Do this **before** the password protection in Part 7. The certificate check needs plain web access.

**6.1 Issue the certificate.**

- Where: cPanel, **SSL/TLS Status**
- Do: find `staging.groibe.com`, tick it, press **Run AutoSSL**. Wait 2 to 5 minutes and reload the page.
- Success: the row shows a padlock and "AutoSSL Domain Validated". `www.staging.groibe.com` may stay red. That is fine: it has no DNS record and nobody uses it.
- If it fails: a domain-validation error nearly always means DNS has not spread yet (5.3) or the cloud is orange. Fix it, then run AutoSSL again.
- [ ] Done

**6.2 Check HTTPS works and plain HTTP is redirected.** The app is not installed yet, so the page itself will show an error. That is fine. Use `curl.exe` (with `.exe`), because plain `curl` in PowerShell is a different command.

- Where: PC
- Do:

  ```powershell
  curl.exe -sSI https://staging.groibe.com/
  curl.exe -sI http://staging.groibe.com/
  curl.exe -sI http://staging.groibe.com/.well-known/acme-challenge/test
  ```
- Success: the first prints an `HTTP/...` line (any number, for example 403 or 500) and **no** line about a certificate problem. The second prints `HTTP/1.1 301` and `Location: https://staging.groibe.com/`. The third does **not** print 301 (a 404 is right).
- If the second does not print 301: the rule in `public/.htaccess` is not being read, or cPanel's own redirect switch is on (turn it off in Domains). Stop and tell me. This rule has only been tested in code, not on this host.
- [ ] Done

---

## Part 7. cPanel: HTTP login for the whole staging site

Staging holds test data and must not be open to everyone. It already tells search engines to stay away, but that is not protection.

**7.1 Protect the folder above `public`.**

- Where: cPanel, **Directory Privacy**
- Do: click the name `groibe-staging` (click the **name**, not the folder icon). Choose **Password protect this directory**. Name: `Groibe staging`. Save. Then under **Create User** add the name and password from decision D3 (the name you chose and the password from your password manager). Save.
- Why the parent folder: cPanel writes its rules into the `.htaccess` of the folder you protect. `public/.htaccess` is tracked in Git, and `scripts/deploy.sh` refuses to run when a tracked file has changed. The parent folder has no `.htaccess`, so cPanel makes a new one that Git does not track. **Never protect `public_html`.**
- Success: you see "Password protect this directory" ticked for `/home/groibe_c1/groibe-staging`.
- [ ] Done

**7.2 Prove the login works and keep Git clean.**

- Where: PC, then SSH
- Do:

  ```powershell
  curl.exe -sI https://staging.groibe.com/
  ```

  Then on the server:

  ```bash
  cd ~/groibe-staging
  git status --short
  echo "/.htaccess" >> .git/info/exclude
  git status --short
  ```
- Success: the PC command prints `HTTP/1.1 401` and a `WWW-Authenticate: Basic` line. On the server, the first `git status --short` may show `?? .htaccess`, and the second shows **nothing**.
- If the PC command prints 200 or 403 instead of 401: the parent folder's `.htaccess` is not read on this host. Stop and tell me. Do not move the protection into `public` (see the mismatch table, item 6).
- Later: AutoSSL renews the certificate in about 60 to 90 days. A login in front of the site can block that. Look at cPanel **SSL/TLS Status** every month while staging runs. If a renewal fails, switch the protection off for a few minutes, run AutoSSL, and switch it on again.
- [ ] Done

---

## Part 8. cPanel and SSH: database and backup folder

**8.1 Check the staging database is its own, and empty.** Staging uses `groibe_c1_staging` and its own user (decision D1). It never uses `groibe_c1_groibe`, which is production.

- Where: cPanel, **MySQL Databases**
- Do: confirm `groibe_c1_staging` is listed, and that **its own user** is added to it with **All Privileges**. That user must not be added to `groibe_c1_groibe`, and the production user must not be added to `groibe_c1_staging`. Write down the staging user name.
- Where: SSH
- Do (replace `STAGINGUSER`; it asks for the password, type it there):

  ```bash
  mysql -u STAGINGUSER -p groibe_c1_staging -e "SELECT DATABASE(), VERSION(); SHOW TABLES;"
  ```

  Then the cross check. It asks for the staging password again and **must be refused**:

  ```bash
  mysql -u STAGINGUSER -p groibe_c1_groibe -e "SELECT 1;"
  ```
- Success: the first command prints `groibe_c1_staging` and a `11.4...-MariaDB` version, and **no table names** follow. The cross check prints `ERROR 1044` or `ERROR 1045` (access denied).
- If any table is listed: **stop**. This may not be an empty database, and the WordPress database must never be used. Tell me what it lists.
- If the cross check **works** (it prints `1`): **stop**. The staging user can reach production. In cPanel, **MySQL Databases**, remove that user from `groibe_c1_groibe`.
- [ ] Done

**8.2 Make the backup folder, outside the web folders.**

- Where: SSH
- Do:

  ```bash
  mkdir -p ~/groibe-backups-staging && chmod 700 ~/groibe-backups-staging
  ls -ld ~/groibe-backups-staging
  ```
- Success: it prints `drwx------`.
- [ ] Done

---

## Part 9. PC and SSH: put the built assets on the server

- Where: PC
- Do:

  ```powershell
  scp -P 22 "$env:TEMP\groibe-build.tgz" groibe_c1@45.199.139.16:groibe-build.tgz
  ```
- Where: SSH
- Do:

  ```bash
  cd ~/groibe-staging
  rm -rf public/build
  tar -xzf ~/groibe-build.tgz -C public
  rm ~/groibe-build.tgz
  ls public/build/manifest.json
  ```
- Success: the last line prints `public/build/manifest.json`.
- [ ] Done

---

## Part 10. SSH: the `.env` file, packages and the app key

**10.1 Make `.env` from the template.**

- Where: SSH
- Do:

  ```bash
  cd ~/groibe-staging
  cp .env.production.example .env
  chmod 600 .env
  nano .env
  ```

  Set these lines. Leave all others as they are. Save with Ctrl+O, Enter, then exit with Ctrl+X.

  | Line | Value |
  |---|---|
  | `APP_ENV` | `staging` |
  | `APP_DEBUG` | `false` |
  | `APP_KEY` | leave empty (10.3 fills it) |
  | `APP_URL` | `https://staging.groibe.com` (no slash at the end) |
  | `DB_CONNECTION` | `mysql` (keep it: with any other word, the search indexes in Part 16 are never made) |
  | `DB_HOST` | `localhost` |
  | `DB_DATABASE` | `groibe_c1_staging`. **Never** `groibe_c1_groibe` (production). |
  | `DB_USERNAME`, `DB_PASSWORD` | the staging database user from 8.1, and its password from your password manager |
  | `DB_SIZE_LIMIT_MB` | a little under the real database limit of your plan (ask MilesWeb if unknown) |
  | `MAIL_MAILER` | `smtp` (never `log`) |
  | `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | filled in Part 13. Leave them empty for now. |
  | `ADMIN_EMAIL` | the mailbox you will read, for the first super admin |
  | `ADMIN_PASSWORD` | a long temporary password. You delete it in 11.4. |
  | `GROIBE_CONTACT_EMAIL`, `GROIBE_EXCEPTION_EMAIL` | addresses someone reads |
  | `TRUSTED_PROXIES` | empty (the cloud is grey, so there is no proxy) |
  | `GROIBE_BACKUP_PATH` | `/home/groibe_c1/groibe-backups-staging` |
  | `CSP_REPORT_ONLY` | `false` |

  A value with `#`, `$`, a space or a quote in it must be inside double quotes, for example `DB_PASSWORD="my#pass"`.
- Success:

  ```bash
  grep -E "^(APP_ENV|APP_DEBUG|APP_URL|DB_CONNECTION|MAIL_MAILER|TRUSTED_PROXIES)=" .env
  ```

  prints `staging`, `false`, your URL, `mysql`, `smtp` and an empty `TRUSTED_PROXIES`.

  **Check that staging does not point at production.** Both commands must pass before you go on:

  ```bash
  grep '^DB_DATABASE=' .env
  grep -c '^DB_DATABASE=groibe_c1_staging$' .env
  grep -c 'groibe_c1_groibe' .env
  ```

  The first prints `DB_DATABASE=groibe_c1_staging`, the second prints `1`, and the third prints `0` (the production database name appears nowhere in the staging `.env`). If the third prints anything but `0`, or `DB_DATABASE` is anything else, **stop and fix `.env`**. Do not run Part 11. The same check is repeated in 11.1, because the deploy runs migrations.
- [ ] Done

**10.2 Install the PHP packages.**

- Where: SSH
- Do:

  ```bash
  export COMPOSER="$PHP $(command -v composer)"
  $COMPOSER install --no-dev --optimize-autoloader --no-interaction
  ```
- Success: it ends with lines about generating autoload files, and no red error.
- If it prints `Killed`: the shared server ran out of memory. Run `COMPOSER_MEMORY_LIMIT=-1 $COMPOSER install --no-dev --optimize-autoloader --no-interaction`.
- [ ] Done

**10.3 Make the app key, once.**

- Where: SSH
- Do:

  ```bash
  $PHP artisan key:generate --force
  grep -c '^APP_KEY=base64:' .env
  ```
- Success: the second command prints `1`. **Copy the `APP_KEY=...` line from `.env` into your password manager now.** Without it, saved data (for example two-factor secrets) cannot be read after a restore. Never reuse your development key.
- [ ] Done

---

## Part 11. SSH: first deploy, first migrate, safe seeders

**Never run on this server:** `migrate:fresh`, `migrate:refresh`, `db:wipe`, `DemoSeeder`, or `db:seed --class=DemoSeeder`. They wipe the database or add fake people.

**11.1 Run the safety checks.** They change nothing.

- Where: SSH
- Do:

  ```bash
  cd ~/groibe-staging
  PHP_BIN=$PHP COMPOSER_BIN="$COMPOSER" bash scripts/deploy.sh check
  $PHP artisan tinker --execute='echo config("database.connections.mysql.database"), " ", config("app.env");'
  ```
- Success: `All checks passed. Nothing was changed.` The last line prints exactly `groibe_c1_staging staging`. If it prints `groibe_c1_groibe`, or `production`, **stop**: staging points at production. Fix `.env` (10.1) and run `$PHP artisan config:clear` before anything else. Never run 11.2 until this line is right.
- If it fails: the message says what is wrong (`.env` missing, `APP_DEBUG` not false, `APP_KEY` empty, `public/build/manifest.json` missing, a tracked file changed). Fix it and run again.
- [ ] Done

**11.2 Deploy.** This also runs the migrations.

- Where: SSH
- Do:

  ```bash
  PHP_BIN=$PHP COMPOSER_BIN="$COMPOSER" bash scripts/deploy.sh
  ```
- Success: you see "The database has no tables yet, so this is a first deploy. The backup is skipped.", then the migrations running, then `Done. Now at <commit>`. Near the end it may print "Warning: the health check did not answer". On staging that is **expected**: the password login answers `401`.
- If it stops half way: the site stays in maintenance mode on purpose. Read the error, fix it, run the same command again.
- [ ] Done

**11.3 Add the first data, once.** The safe seeders are: roles and permissions, the super admin from `ADMIN_EMAIL` and `ADMIN_PASSWORD`, skills and project types, feature flags, agreement placeholders, and website text (stored inactive).

- Where: SSH
- Do:

  ```bash
  $PHP artisan db:seed --force
  $PHP artisan tinker --execute='echo App\Models\User::count(), " user(s), ", App\Models\Organization::count(), " organization(s)";'
  ```
- Success: it prints `1 user(s), 0 organization(s)`. That proves there is no demo data.
- [ ] Done

**11.4 Remove the temporary password from `.env`.**

- Where: SSH
- Do: `nano .env`, delete the value after `ADMIN_PASSWORD=` so the line reads `ADMIN_PASSWORD=`. Save. Then:

  ```bash
  $PHP artisan config:cache
  grep '^ADMIN_PASSWORD=' .env
  ```
- Success: it prints exactly `ADMIN_PASSWORD=`.
- [ ] Done

---

## Part 12. cPanel: the cron line

- Where: cPanel, **Cron Jobs**, **Add New Cron Job**
- Do: Common Settings: **Once Per Minute (`* * * * *`)**. Command, in one line:

  ```
  cd /home/groibe_c1/groibe-staging && /opt/ecp-php83/bin/php artisan schedule:run >> /dev/null 2>&1
  ```

  It must use **your** PHP path, not the one in DEPLOY.md (mismatch item 1). Add it.
- Success: the entry shows in the list. You prove it runs in Part 14, when System status says "Running".
- If cPanel refuses every minute: ask MilesWeb. At 5 minutes, queued mail is up to 5 minutes late.
- [ ] Done

Check the list of jobs:

- Where: SSH
- Do: `cd ~/groibe-staging && $PHP artisan schedule:list | grep -c 'Next Due'`
- Success: it prints `14`.
- [ ] Done

---

## Part 13. Mail: live SMTP, DNS records, and the emailed admin code

**13.1 Confirm where `groibe.com` mail lives.** Decision D2: it stays at MilesWeb, with the mail records in Cloudflare set to **grey (DNS only)**. Read the live records to confirm that, before you change anything. This protects your real company email.

- Where: PC
- Do:

  ```powershell
  Resolve-DnsName groibe.com -Type MX
  Resolve-DnsName groibe.com -Type TXT
  ```
- Write down the MX names and the `v=spf1` line.
- Where: Cloudflare, `groibe.com`, **DNS**, **Records**. Look at the `MX` records, and at `mail`, `webmail`, `smtp`, `imap`, `pop` and any other record that carries mail. Each must show a **grey** cloud (DNS only). `MX` and `TXT` records have no cloud and cannot be proxied. If an `A` or `CNAME` record used for mail (for example `mail`) is **orange**, click it to make it grey, because a proxied mail host breaks mail. Change nothing else.
- If the MX names belong to **MilesWeb** (the same host as the server), as decided in D2: carry on with 13.2.
- If they belong to **Zoho, Google or another provider**: **stop here and tell me.** This differs from D2. Do not make a cPanel mailbox for `groibe.com` and do not open cPanel **Email Routing**. Staging would then send through that provider's SMTP server instead, and 13.2 and 13.3 change.
- Success: the MX names are MilesWeb's, and every mail-related record that can have a cloud shows grey.
- [ ] Done

**13.2 Make the sending mailbox.**

- Where: cPanel, **Email Accounts**, **Create**
- Do: an address on `groibe.com` for sending, for example `no-reply@groibe.com` (your choice). A strong password. Then press **Connect Devices** for that mailbox and read **Mail Client Manual Settings**: the outgoing server name and port.
- Where: SSH, `nano ~/groibe-staging/.env`
- Do: set `MAIL_HOST` (the outgoing server), `MAIL_PORT` `587` with `MAIL_SCHEME=null`, or `465` with `MAIL_SCHEME=smtps`, `MAIL_USERNAME` (the full address), `MAIL_PASSWORD`, and `MAIL_FROM_ADDRESS` (the same address). Save. Then `cd ~/groibe-staging && $PHP artisan config:cache`.
- Success: `grep -E "^MAIL_(MAILER|HOST|PORT|USERNAME|FROM_ADDRESS)=" .env` shows `smtp` and your values. The password line is not shown by this command on purpose.
- [ ] Done

**13.3 SPF, DKIM and DMARC.** Without them, the emailed sign-in code lands in spam, and you cannot sign in.

- Where: cPanel, **Email Deliverability**, `groibe.com`, **Manage**
- Do: read what it suggests. DNS is on Cloudflare, so copy each suggested record into Cloudflare, as a `TXT` record (a `TXT` record has no proxy, so it is always DNS only). If cPanel suggests an `A` or `CNAME` record for mail, add it **grey (DNS only)** (decision D2). Before you add an SPF record, look at the `v=spf1` line you wrote down in 13.1. If one exists, **edit that one record** to add what cPanel suggests. **Never create a second `v=spf1` record.** Never delete a record you did not make. For DMARC, if `_dmarc` does not exist, add a `TXT` named `_dmarc` with:

  ```
  v=DMARC1; p=none; rua=mailto:ADDRESS-SOMEONE-READS; fo=1
  ```
- Success: cPanel **Email Deliverability** shows SPF and DKIM as valid for `groibe.com`.
- [ ] Done

**13.4 Send a plain test email.**

- Where: SSH
- Do (replace the address with one you read, ideally Gmail):

  ```bash
  cd ~/groibe-staging
  $PHP artisan tinker --execute='Mail::raw("Groibe staging test", fn($m) => $m->to("you@example.com")->subject("Groibe staging test"));'
  ```
- Success: the email arrives within a minute. In Gmail, **Show original** shows `SPF: PASS`, `DKIM: PASS` and `DMARC: PASS`. Also send one to a Microsoft address and check its spam folder.
- If it does not arrive: check the host's email limit, the `.env` mail lines, and the spam folder. Fix this before the next step.
- [ ] Done

**13.5 Test the emailed admin sign-in code, before you rely on it.** The code is sent at once and can never be read on the server, so if mail is broken you cannot sign in. Keep your SSH window open as your way back.

- Where: Browser, `https://staging.groibe.com/admin/login` (it first asks for the HTTP login from D3)
- Do: enter `ADMIN_EMAIL` and the temporary password from 10.1. The page asks for a 6-digit code.
- Success, all five:
  1. The email "Your Groibe admin sign-in code" arrives within about a minute.
  2. In Gmail "Show original", SPF, DKIM and DMARC pass.
  3. A wrong code is refused. The right code lets you in.
  4. Signing out and in again, the **same** code is refused.
  5. In **System, Email Log** there is a row with template `AdminSignInCode` and status `sent`, and no row shows a code.
- Then change the super admin password to your own, in Admin, and store it in your password manager.
- If the email does not arrive: do not carry on. Follow [`docs/OPERATIONS.md`](OPERATIONS.md) section 4. Do **not** switch to `MAIL_MAILER=log`: that writes every code into a file.
- [ ] Done

---

## Part 14. Smoke test

Tick every line. These are the lines of DEPLOY.md section 15 that apply to a staging site with no demo data. Replace `STAGING-LOGIN` with the login name you chose in D3 (type it into your own window only; do not save this file with the name in it). The first command asks you for the password and keeps it only for this window.

- Where: PC
- Do:

  ```powershell
  $u = "STAGING-LOGIN:" + (Read-Host "Staging HTTP password")
  curl.exe -s -o NUL -w "%{http_code}`n" -u $u https://staging.groibe.com/up
  curl.exe -sI -u $u https://staging.groibe.com/ | Select-String -Pattern "strict-transport|x-frame|x-content-type|referrer-policy|content-security|x-robots"
  curl.exe -s -u $u https://staging.groibe.com/robots.txt
  curl.exe -s -o NUL -w "%{http_code}`n" -u $u https://staging.groibe.com/sitemap.xml
  foreach ($p in '/.env','/.git/config','/composer.json','/artisan','/vendor/autoload.php','/storage/logs/laravel.log','/storage/app/private','/storage/app/public','/public/storage','/backups') { "{0,-30} {1}" -f $p, (curl.exe -s -o NUL -w "%{http_code}" -u $u "https://staging.groibe.com$p") }
  ```

**The site**

- [ ] `/up` prints `200`.
- [ ] The headers include `strict-transport-security`, `x-frame-options: DENY`, `x-content-type-options: nosniff`, `referrer-policy`, `content-security-policy`, and `x-robots-tag: noindex, nofollow` (staging is never indexed).
- [ ] `robots.txt` says `Disallow: /`. `sitemap.xml` prints `404`.
- [ ] Every path in the loop prints `403` or `404`, **never `200`**. If any prints 200, stop: the document root is wrong (Part 4.1).
- [ ] In the Browser: the home page opens over HTTPS with no red errors in the console, and a page that does not exist shows the 404 page, not a stack trace. The session cookie is `Secure`, `HttpOnly` and `SameSite=Lax` (dev tools, Application, Cookies).
- [ ] Six wrong passwords at `/login` in a minute are refused.

**Admin and cron** (Browser, signed in at `/admin`)

- [ ] Dashboard, **System status**: cron says **Running** (allow two minutes after Part 12), jobs waiting 0, failed jobs 0, a database size line (it says "Not checked yet" until 03:20 or `$PHP artisan groibe:check-database-size`).
- [ ] No red alert line on the dashboard.

**Mail and queue**

- [ ] The contact form on the site (use the browser) sends a message that arrives at `GROIBE_CONTACT_EMAIL` within two minutes, and shows under Website, Contact Messages. This proves cron and the queue.
- [ ] **System, Email Log** lists the sent mails as `sent`.
- [ ] Visitor address check. On the server:

  ```bash
  cd ~/groibe-staging
  $PHP artisan tinker --execute='echo App\Models\ContactMessage::latest("id")->value("ip");'
  ```

  It prints **your** public address, not a Cloudflare one.
- [ ] On the server: `$PHP artisan tinker --execute='report(new Exception("Deploy test"));'` sends one email to `GROIBE_EXCEPTION_EMAIL`. Running it again within the hour sends none.

**Files and backups**

- [ ] `$PHP artisan groibe:backup-db` writes a file into `/home/groibe_c1/groibe-backups-staging`, not into the web folder.
- [ ] Upload a profile photo in a test account: it shows. Open a SOW as PDF, once you have one: it downloads set in Inter.

**What this smoke test cannot cover yet.** Without demo data, no client can submit a brief and no contributor can finish onboarding until agreement wording is published. To test private files and the SOW PDF, publish the **test wording** in DEPLOY.md section 22 (staging only), then do the by-hand private-file test in DEPLOY.md section 9. Do not do this on production.

- [ ] Done (or noted as left for a later day)

---

## Part 15. Backup restore drill, into a scratch database

This proves a dump can really be restored. **Never restore into `groibe_c1_staging` or `groibe_c1_groibe`.** Only into the scratch database.

**15.1 Take a fresh dump.**

- Where: SSH
- Do:

  ```bash
  cd ~/groibe-staging
  $PHP artisan groibe:backup-db
  ls -l ~/groibe-backups-staging
  ```
- Success: the command prints a line `BACKUP_FILE=/home/groibe_c1/groibe-backups-staging/groibe-db-YYYYMMDD-HHMMSS.sql.gz`. The file shows `-rw-------` and is larger than 4 KB. Note the file name.
- [ ] Done

**15.2 Make the scratch database and its user.**

- Where: cPanel, **MySQL Databases**
- Do: **Create New Database**: name `scratch` (the full name becomes `groibe_c1_scratch`). **Add New User**: a name and a strong password. **Add User To Database**: that user on `groibe_c1_scratch`, **All Privileges**. Write down the **exact** user name cPanel shows. cPanel may shorten it.
- Success: both show in the lists.
- If cPanel refuses: your plan may limit the number of databases. Ask MilesWeb.
- [ ] Done

**15.3 Restore into the scratch database.**

- Where: SSH
- Do (replace the file name and `SCRATCHUSER`; it asks for the password):

  ```bash
  gunzip -c ~/groibe-backups-staging/groibe-db-YYYYMMDD-HHMMSS.sql.gz | mysql -u SCRATCHUSER -p groibe_c1_scratch
  ```
- Success: it ends with no error text.
- [ ] Done

**15.4 Compare the counts, and check the search indexes came across.**

- Where: SSH
- Do (it asks for each password):

  ```bash
  Q="SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM roles) AS roles, (SELECT COUNT(*) FROM migrations) AS migrations;"
  mysql -u SCRATCHUSER -p groibe_c1_scratch -e "$Q"
  mysql -u STAGINGUSER -p groibe_c1_staging -e "$Q"
  mysql -u SCRATCHUSER -p groibe_c1_scratch -e "SHOW INDEX FROM profiles WHERE Index_type = 'FULLTEXT';"
  ```
- Success: the two count rows are identical (users `1`). The third command lists the FULLTEXT index `profiles_headline_bio_fulltext` on `headline` and `bio`. Do not compare `sessions` or `cache`: the dump leaves them out on purpose.
- [ ] Done

**15.5 Delete the scratch database and user.**

- Where: cPanel, **MySQL Databases**, delete the database `groibe_c1_scratch` and then its user.
- Success: neither is listed. Write the date and the result in the team log.
- [ ] Done

---

## Part 16. The MariaDB check for the FULLTEXT index on `profiles(headline, bio)`

Why this matters: search on `/contributors` uses `MATCH ... AGAINST`. The index is only created when `DB_CONNECTION` is exactly `mysql`, and the migration skips it **silently** for any other value. A missing index makes the public search fail.

- Where: SSH
- Do (it asks for the password):

  ```bash
  mysql -u STAGINGUSER -p groibe_c1_staging -e "
  SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND INDEX_TYPE = 'FULLTEXT'
   GROUP BY TABLE_NAME, INDEX_NAME;
  SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles';
  SELECT COUNT(*) FROM profiles WHERE MATCH(headline, bio) AGAINST ('test');"
  ```
- Success:
  1. Three FULLTEXT rows: `open_roles (title,summary)`, `proof_of_work (title,summary)` and `profiles (headline,bio)`.
  2. The engine is `InnoDB`.
  3. The last query prints a count (`0` is fine) and **no error**. The error `Can't find FULLTEXT index matching the column list` means the index is missing.
- If the index is missing: do not hand-create it on this server. Check that `DB_CONNECTION=mysql` in `.env`, and tell me. A new migration would be needed, and that is a code change for a later pass.
- Good to know: this server ignores words of one or two letters in FULLTEXT search, and common words. That is normal, not a fault.
- [ ] Done

---

## Part 17. Cutover of groibe.com, and rollback to WordPress

**Do not do this on the first staging day.** Do it only after staging has passed Parts 14 to 16, the real legal texts are published (DEPLOY.md section 16, step 4), and checked the staging and production database names (D1). This is the only part that can change what visitors of `groibe.com` see.

**17.1 Back up WordPress completely. This is your rollback.**

- Where: SSH (read only on WordPress)
- Do:

  ```bash
  cd ~
  grep -E "DB_NAME|DB_USER" ~/public_html/wp-config.php
  tar -czf ~/wp-public_html-$(date +%Y%m%d).tgz public_html
  ls -l ~/wp-public_html-*.tgz
  ```

  Write down the `DB_NAME`. Never print or share the password line.
- Where: cPanel, **Backup Wizard**, **Backup**, **MySQL Databases**: download the WordPress database. Also **Home Directory** if you want the cPanel copy. Download the `.tgz` above to your computer too (SFTP or `scp`).
- Where: Cloudflare, `groibe.com`, **DNS**, **Records**, **Export**: save the DNS file. Write down, from **SSL/TLS** and **Caching**, the current encryption mode and any rules.
- Success: three things are on your computer: the files archive, the WordPress database dump, and the DNS export. Open the archive and see `wp-config.php` inside. A backup you have never opened is not a backup.
- [ ] Done

**17.2 Build production beside staging, without touching `groibe.com`.**

- Where: SSH, with the production database `groibe_c1_groibe` and its own production user (decision D1). It must be empty, and the staging user must not be added to it
- Do: repeat Parts 3.4, 8, 9 and 10 to 11 for the production folder `~/groibe`, with: clone into `groibe` (the deploy key is reused), `DB_DATABASE=groibe_c1_groibe` with the production database user (and `groibe_c1_staging` nowhere in this `.env`), `APP_ENV=production`, `APP_URL=https://groibe.com`, `GROIBE_BACKUP_PATH=/home/groibe_c1/groibe-backups`, new `APP_KEY`, the **real** mail settings, and **no** HTTP login. In place of Part 11.2 run only the check: `PHP_BIN=$PHP COMPOSER_BIN="$COMPOSER" bash scripts/deploy.sh check`. The real deploy runs after the switch.

  Mirror the checks of 8.1, 10.1 and 11.1 for production: the production user is refused on `groibe_c1_staging`, `grep -c 'groibe_c1_staging' .env` prints `0`, and the tinker line prints `groibe_c1_groibe production`.

  For production, the cron line uses `cd /home/groibe_c1/groibe` (do not add it until the switch).
- Success: `check` prints `All checks passed. Nothing was changed.` and `groibe.com` still shows WordPress.
- [ ] Done

**17.3 Switch the document root.** Pick a quiet time.

- Where: cPanel, **Domains**, `groibe.com`, **Manage**, **Document Root**
- Do: change `public_html` to `groibe/public`. Update. Then deploy and seed on the server (Part 11.2 to 11.4, with the production folder), and add the cron line for `~/groibe`.
- If cPanel does not let you change the main domain's document root: ask MilesWeb support to change it. Do **not** copy files into `public_html`.
- Success: `curl.exe -sI https://groibe.com/` answers from the new app, and the browser shows the Groibe site, not WordPress.
- [ ] Done

**17.4 Cloudflare, after the app answers.**

- Where: Cloudflare
- Do, in this order: **Caching, Configuration, Purge Everything** (so no cached WordPress page remains). Then, only if the `groibe.com` record is **orange**, set **SSL/TLS** to **Full (strict)** (the host certificate is already valid) and follow DEPLOY.md section 11, including `TRUSTED_PROXIES` in the production `.env` followed by `config:cache`. If it is grey, leave Cloudflare alone.
- Success: a private browser window on `https://groibe.com` shows the new site. `www.groibe.com` also works.
- [ ] Done

**17.5 Run the production smoke test.** DEPLOY.md section 15, in full, including section 9 (private files), the emailed admin code (section 10, step 7) and section 22's check that test wording is `0` on production. Switch on the uptime monitor (DEPLOY.md section 20).

- [ ] Done

**Things WordPress did that are now gone.** Before the switch, list them: contact forms and where they sent mail, old page addresses that other sites or Google link to (they now answer 404 unless you add redirects), images in `wp-content/uploads` used elsewhere, and any WordPress mail. This list is a decision for you.

### Rollback to WordPress

Use this at any moment, for example if the smoke test fails. WordPress was never changed, so it comes back at once.

1. Where: cPanel, **Domains**, `groibe.com`, **Manage**, **Document Root**: set it back to `public_html`. Update.
2. Where: Cloudflare: **Purge Everything**. Put SSL/TLS and any other setting back as you wrote it down in 17.1.
3. Where: PC: `curl.exe -sI https://groibe.com/` and open the site in a private window. It must show WordPress. Log in to `wp-admin` and send a test from a form.
4. Remove the production cron entry in cPanel so the stopped app does no work. Leave `~/groibe` and its database as they are, so nothing is lost.
5. Only if WordPress files were damaged (they should not be): restore from the archive and database dump of 17.1. Do not extract over `public_html` without checking first what differs. Ask before overwriting.
6. Write down what failed. Fix it on staging, and try again.

Anything entered into the new app between the switch and the rollback stays in its database and is not lost.

---

## When something goes wrong

- A deploy that stops half way leaves the site in maintenance mode on purpose. Fix it and run the deploy again, or run `PHP_BIN=$PHP COMPOSER_BIN="$COMPOSER" bash scripts/deploy.sh rollback` (code only), as in DEPLOY.md section 13.
- A white page or a 500: read the newest file in `~/groibe-staging/storage/logs`. Never turn `APP_DEBUG` on.
- Always send me the exact error text. Never paste a password, the `.env` file or the `APP_KEY`.
