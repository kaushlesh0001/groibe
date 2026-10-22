#!/usr/bin/env bash
#
# Groibe deploy, run over SSH on the shared host (docs/DEPLOY.md).
#
#   scripts/deploy.sh            pull from Git, install, migrate, cache, bring the site back
#   scripts/deploy.sh check      only the safety checks, changes nothing
#   scripts/deploy.sh rollback   go back to the code that was live before the last deploy
#
# It holds no secrets. It reads two harmless lines of .env (APP_ENV, APP_DEBUG) to refuse a wrong setup, and APP_URL for the
# final health check. It never prints a password, and it never runs npm: the built assets are uploaded, not built here.
#
# Settings, all optional, as environment variables:
#   PHP_BIN=/opt/ecp-php83/bin/php                  the PHP 8.3 binary (the `php` on a cPanel SSH login is often older)
#   COMPOSER_BIN=composer                           or `"$PHP_BIN" /home/USER/composer.phar`
#   DEPLOY_BRANCH=main     DEPLOY_REMOTE=origin

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
BRANCH="${DEPLOY_BRANCH:-main}"
REMOTE="${DEPLOY_REMOTE:-origin}"
ACTION="${1:-deploy}"

say() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

artisan() { "$PHP_BIN" artisan "$@"; }

# One word from a line of .env, for the two harmless checks below. Never used for anything secret.
env_value() { { grep -E "^$1=" "$APP_DIR/.env" || true; } | tail -n 1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"//' -e 's/"$//' | tr -d '[:space:]'; }

preflight() {
    cd "$APP_DIR"

    [ "$(id -u)" -ne 0 ] || fail "Do not deploy as root. Use the cPanel account's own SSH login."
    [ -f .env ] || fail "There is no .env file. Copy .env.production.example to .env and fill it in first (docs/DEPLOY.md)."

    # Staging is a second copy of the site on its own subdomain and database. It is the only place demo data may run.
    case "$(env_value APP_ENV)" in
        production | staging) ;;
        *) fail "APP_ENV in .env must be 'production' (or 'staging' on the staging site). A 'local' file here means the wrong .env." ;;
    esac
    [ "$(env_value APP_DEBUG)" = "false" ] || fail "APP_DEBUG is not 'false' in .env. Debug pages show secrets."
    [ -n "$(env_value APP_KEY)" ] || fail "APP_KEY is empty. Run: $PHP_BIN artisan key:generate --force (once, on the server)."

    command -v "$PHP_BIN" >/dev/null || fail "PHP was not found at '$PHP_BIN'. Set PHP_BIN to the PHP 8.3 binary (cPanel, MultiPHP Manager shows its path)."
    command -v git >/dev/null || fail "git is not installed on this account."
    command -v "${COMPOSER_BIN%% *}" >/dev/null || fail "Composer was not found. Set COMPOSER_BIN, for example COMPOSER_BIN=\"$PHP_BIN \$HOME/composer.phar\"."

    "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || fail "PHP is too old. Set PHP_BIN to the PHP 8.3 binary (cPanel, MultiPHP Manager)."
    "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || printf 'Warning: PHP is below 8.3, the version Groibe is built for.\n'
    "$PHP_BIN" -r 'exit(function_exists("proc_open") ? 0 : 1);' || printf 'Warning: proc_open is disabled for the command line. Check it for the web too: the scheduler needs it (DEPLOY.md).\n'

    if ! git diff --quiet || ! git diff --cached --quiet; then
        fail "There are uncommitted changes to tracked files in $APP_DIR. Nothing is edited by hand on the server. Stash or discard them first."
    fi

    # Vite output is not in Git. It is built on a computer and uploaded.
    [ -f public/build/manifest.json ] || fail "public/build/manifest.json is missing. Build with 'npm run build' on a computer and upload public/build first (DEPLOY.md, step 5)."

}

prepare_folders() {
    mkdir -p storage/app/private storage/app/public storage/fonts storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    # Writable by the account and its group, never world-writable.
    chmod -R ug+rwX storage bootstrap/cache
}

rebuild_caches() {
    artisan optimize:clear
    artisan config:cache
    artisan route:cache
    artisan view:cache
    artisan event:cache
}

reset_opcache() {
    # This clears the command-line cache only. The web server keeps its own, and no command here can reach it. It picks up the new
    # files by itself while opcache.validate_timestamps is 1 (the cPanel default). See DEPLOY.md, "After a deploy".
    "$PHP_BIN" -r 'if (function_exists("opcache_reset")) { opcache_reset(); }' || true
}

health_check() {
    local url
    url="$(env_value APP_URL)"

    if [ -n "$url" ] && command -v curl >/dev/null; then
        say "Health check: $url/up"
        curl -fsS --max-time 20 -o /dev/null -w 'HTTP %{http_code}\n' "$url/up" || printf 'Warning: the health check did not answer. Look at storage/logs and the site itself.\n'
    fi
}

on_failure() {
    printf '\nThe deploy stopped part way. The site is LEFT IN MAINTENANCE MODE on purpose: the code and the database may not match.\n' >&2
    printf 'Read the error above, fix it and run this script again. To go back to the last release run: scripts/deploy.sh rollback\n' >&2
    printf 'To bring the site up as it is: %s artisan up\n' "$PHP_BIN" >&2
}

# SCR-037, spec 17.4. A database dump before anything changes. It runs BEFORE `artisan down`, so a failure leaves the site up and
# nothing touched. The exit code alone is not trusted: the file itself must exist and be at least 4 KB (a freshly migrated and seeded database dumps to about
# 7 KB gzipped, so 4 KB still catches an empty or cut-off file). On a first deploy the database has no tables, the command exits with code 2 and the backup is skipped.
MIN_DUMP_BYTES=4096

predeploy_backup() {
    say "Backing up the database before anything changes"

    local output code file size
    set +e
    output="$(artisan groibe:backup-db --predeploy 2>&1)"
    code=$?
    set -e
    printf '%s\n' "$output"

    if [ "$code" -eq 2 ]; then
        say "The database has no tables yet, so this is a first deploy. The backup is skipped."
        return 0
    fi

    [ "$code" -eq 0 ] || fail "The pre-deploy backup failed (exit code $code). Nothing was changed and the site is still up. Fix it (free disk space? GROIBE_BACKUP_PATH?) and run this script again."

    file="$(printf '%s\n' "$output" | sed -n 's/^BACKUP_FILE=//p' | tail -n 1)"
    { [ -n "$file" ] && [ -f "$file" ]; } || fail "The pre-deploy backup reported success but the file is missing. Nothing was changed and the site is still up."

    size="$(wc -c < "$file" | tr -d '[:space:]')"
    [ "$size" -ge "$MIN_DUMP_BYTES" ] || fail "The pre-deploy backup file is only $size bytes (under $MIN_DUMP_BYTES). Nothing was changed and the site is still up. Look at $file before going on."

    # The size cannot tell a complete dump from one that is cut off. `gzip -t` reads the whole compressed file and checks it.
    command -v gzip >/dev/null || fail "gzip is not installed, so the pre-deploy backup cannot be checked. Nothing was changed and the site is still up."
    gzip -t "$file" 2>/dev/null || fail "The pre-deploy backup file is not a complete gzip file (cut off or damaged). Nothing was changed and the site is still up. Look at $file before going on."

    say "Pre-deploy backup written: $(basename "$file") ($size bytes)"
}

deploy() {
    preflight
    prepare_folders
    predeploy_backup

    say "Releasing $(git rev-parse --short HEAD) on $BRANCH"
    git rev-parse HEAD > storage/framework/previous-release

    trap on_failure ERR
    artisan down --retry=60 --refresh=15

    say "Pulling $REMOTE/$BRANCH"
    git checkout "$BRANCH"
    git pull --ff-only "$REMOTE" "$BRANCH"

    say "Installing PHP packages (no dev tools)"
    $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

    say "Running migrations"
    artisan migrate --force

    say "Publishing admin panel assets"
    artisan filament:assets

    prepare_folders

    say "Rebuilding caches"
    rebuild_caches
    artisan queue:restart || true
    reset_opcache


    artisan up
    trap - ERR

    health_check
    say "Done. Now at $(git rev-parse --short HEAD). Next: the smoke test in docs/DEPLOY.md."
}

rollback() {
    cd "$APP_DIR"
    [ -f storage/framework/previous-release ] || fail "No previous release is recorded (storage/framework/previous-release). Roll back by hand (DEPLOY.md)."

    local previous
    previous="$(cat storage/framework/previous-release)"

    say "Going back to ${previous:0:7}"
    trap on_failure ERR
    artisan down --retry=60 --refresh=15

    git checkout --detach "$previous"
    $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress
    prepare_folders
    rebuild_caches
    reset_opcache

    artisan up
    trap - ERR

    health_check
    printf '\nRolled back the CODE only. Migrations are not undone. If the release you left added or changed tables, restore the database\n'
    printf 'from a backup (DEPLOY.md, "Backups and the restore drill"). Before the next deploy this script returns to %s by itself.\n' "$BRANCH"
}

case "$ACTION" in
    deploy) deploy ;;
    check) preflight; say "All checks passed. Nothing was changed." ;;
    rollback) rollback ;;
    *) fail "Unknown action '$ACTION'. Use: deploy, check or rollback." ;;
esac
