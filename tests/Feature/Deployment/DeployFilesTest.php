<?php

use Dotenv\Dotenv;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/*
 * Pass 10: the deployment files are code too. A template with a development setting, a script that prints a secret, or a guide
 * that names a command that does not exist would each cost an hour on the day of a release. These tests keep them honest.
 */

function deployFile(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/** The values of .env.production.example as Laravel would read them, comments removed. @return array<string, ?string> */
function productionTemplate(): array
{
    return Dotenv::parse(deployFile('.env.production.example'));
}

// ---- .env.production.example -------------------------------------------------------------------------------------

it('sets the template for production with debugging off and the shared-host drivers', function () {
    $env = productionTemplate();

    expect($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['DB_CONNECTION'])->toBe('mysql')
        ->and($env['QUEUE_CONNECTION'])->toBe('database')
        ->and($env['SESSION_DRIVER'])->toBe('database')
        ->and($env['CACHE_STORE'])->toBe('file')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($env['MEDIA_DISK'])->toBe('local')
        ->and($env['MAIL_MAILER'])->toBe('smtp')
        ->and($env['LOG_CHANNEL'])->toBe('daily')
        ->and($env['LOG_DAILY_DAYS'])->toBe('14')
        // The daily database size check is logged at info level (SCR-034), so the level must let it through.
        ->and($env['LOG_LEVEL'])->toBe('info');
});

it('ships the template with no secret and no real address in it', function () {
    $env = productionTemplate();

    foreach (['APP_KEY', 'DB_USERNAME', 'DB_PASSWORD', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'ADMIN_EMAIL', 'ADMIN_PASSWORD', 'GROIBE_CONTACT_EMAIL', 'GROIBE_EXCEPTION_EMAIL', 'GROIBE_BACKUP_PATH'] as $key) {
        expect(array_key_exists($key, $env))->toBeTrue("{$key} is missing from the template")
            ->and((string) $env[$key])->toBe('', "{$key} must be empty in the template");
    }

    expect(deployFile('.env.production.example'))->not->toMatch('/(sk_live|AKIA[0-9A-Z]{12}|BEGIN (RSA|PRIVATE)|base64:[A-Za-z0-9+\/=]{20,})/')
        ->and(deployFile('.env.production.example'))->not->toContain('REDIS');
});

it('lists every setting the Groibe config reads, so none is forgotten on the server', function () {
    $env = productionTemplate();

    preg_match_all("/env\('([A-Z_]+)'/", deployFile('config/groibe.php'), $matches);

    foreach (array_unique($matches[1]) as $key) {
        expect(array_key_exists($key, $env))->toBeTrue("{$key} is read by config/groibe.php but is not in .env.production.example");
    }
});

it('keeps the real .env and the production .env out of Git', function () {
    $ignored = explode("\n", str_replace("\r", '', deployFile('.gitignore')));

    expect($ignored)->toContain('.env')->toContain('.env.production')->toContain('/storage/backups');
});

it('reads no setting outside the config files, so cached config keeps working', function () {
    foreach (['app', 'routes', 'database', 'bootstrap'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($folder), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            expect(preg_match('/(?<![A-Za-z_>:])env\(/', (string) file_get_contents($file->getPathname())))->toBe(0, "env() is called in {$file->getPathname()}");
        }
    }
});

it('builds the config, route, view and event caches the deploy script relies on', function () {
    try {
        foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $command) {
            expect(Artisan::call($command))->toBe(0, "{$command} failed: ".Artisan::output());
        }
    } finally {
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            Artisan::call($command);
        }
    }

    expect(file_exists(base_path('bootstrap/cache/routes-v7.php')))->toBeFalse()
        ->and(file_exists(base_path('bootstrap/cache/config.php')))->toBeFalse();
});

// ---- scripts/deploy.sh -------------------------------------------------------------------------------------------

it('has a deploy script that is a plain shell script with Unix line endings that stops on the first error', function () {
    $script = deployFile('scripts/deploy.sh');

    expect($script)->toStartWith('#!/usr/bin/env bash')
        ->and($script)->toContain('set -euo pipefail')
        ->and($script)->not->toContain("\r");
});

it('marks the deploy script executable in Git', function () {
    if (shell_exec('git --version 2>&1') === null || ! str_contains((string) shell_exec('git --version 2>&1'), 'git version')) {
        $this->markTestSkipped('git is not available here');
    }

    $entry = (string) shell_exec('git ls-files -s '.escapeshellarg(base_path('scripts/deploy.sh')).' 2>&1');

    // Committed with `git update-index --chmod=+x`, so it runs after a pull on a Linux host.
    expect($entry)->toStartWith('100755');
});

it('does the deploy steps in the order the guide describes, and nothing it should not', function () {
    $script = deployFile('scripts/deploy.sh');

    // The order that matters is the one inside deploy(), which calls the helpers (prepare_folders, rebuild_caches) at the right moment.
    preg_match('/^deploy\(\) \{
(.*?)
\}
/ms', $script, $match);
    $deploy = $match[1] ?? '';

    $order = ['artisan down', 'git pull --ff-only', 'install --no-dev --optimize-autoloader', 'migrate --force', 'filament:assets', 'rebuild_caches', 'queue:restart', 'artisan up'];
    $last = 0;

    foreach ($order as $step) {
        $at = strpos($deploy, $step, $last);

        expect($at)->not->toBeFalse("'{$step}' is missing or out of order in deploy()");
        $last = $at;
    }

    // The helper builds all four caches, after clearing the old ones.
    preg_match('/^rebuild_caches\(\) \{
(.*?)
\}
/ms', $script, $helper);

    expect(strpos($helper[1], 'optimize:clear'))->toBeLessThan(strpos($helper[1], 'config:cache'))
        ->and($helper[1])->toContain('route:cache')->toContain('view:cache')->toContain('event:cache');

    // The failure handler leaves the site down on purpose, and the build never runs on the server.
    expect($script)->toContain('trap on_failure ERR')
        ->and($script)->not->toMatch('/^\s*(npm|npx|yarn)\s/m')->not->toContain('chmod 777')->not->toContain('chmod -R 777')
        ->and($script)->not->toContain('rm -rf')->not->toContain('migrate:fresh')->not->toContain('db:wipe')->not->toContain('DemoSeeder')
        ->and($script)->not->toContain('storage:link');
});

it('holds no secret and never prints the contents of .env', function () {
    $script = deployFile('scripts/deploy.sh');

    expect($script)->not->toMatch('/\b(PASSWORD|SECRET|TOKEN|API_KEY|APP_KEY)\s*=\s*\S/')
        ->and($script)->not->toMatch('/\bcat\s+[^|;]*\.env\b/')
        ->and($script)->not->toMatch('/echo[^\n]*(PASSWORD|SECRET|TOKEN)/i')
        // The only .env lines it reads are the harmless ones.
        ->and($script)->toContain('env_value APP_ENV')->toContain('env_value APP_DEBUG')->toContain('env_value APP_URL');
});

it('refuses a wrong setup before it changes anything', function () {
    $script = deployFile('scripts/deploy.sh');

    foreach (['There is no .env file', "APP_DEBUG is not 'false'", 'APP_KEY is empty', 'public/build/manifest.json is missing', 'uncommitted changes', 'Do not deploy as root'] as $message) {
        expect($script)->toContain($message);
    }
});

it('passes a shell syntax check', function () {
    $bash = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where bash 2>NUL' : 'command -v bash'));

    if ($bash === '') {
        $this->markTestSkipped('bash is not available here');
    }

    exec(escapeshellarg(strtok($bash, "\r\n")).' -n '.escapeshellarg(base_path('scripts/deploy.sh')).' 2>&1', $output, $status);

    expect($status)->toBe(0, implode("\n", $output));
});

// ---- docs/DEPLOY.md ----------------------------------------------------------------------------------------------

it('covers every topic Pass 10 names', function () {
    $guide = deployFile('docs/DEPLOY.md');

    foreach ([
        'PHP 8.3', 'MultiPHP', 'document root', 'public_html', 'install --no-dev --optimize-autoloader', 'npm run build', 'public/build',
        '.env', 'key:generate', 'migrate', 'config:cache', 'route:cache', 'view:cache', 'schedule:run', 'storage/app/private', 'storage:link',
        'Rollback', 'restore drill', 'SPF', 'DKIM', 'DMARC', 'Cloudflare', 'smoke test',
    ] as $topic) {
        expect($guide)->toContain($topic);
    }
});

it('carries the Pass 9 notes into the guide', function () {
    $guide = deployFile('docs/DEPLOY.md');

    foreach (['GROIBE_BACKUP_PATH', 'storage/fonts', 'proc_open', 'DB_SIZE_LIMIT_MB', 'DB_CONNECTION', 'GROIBE_EXCEPTION_EMAIL', 'every minute'] as $note) {
        expect($guide)->toContain($note);
    }
});

it('names only commands that exist', function () {
    $guide = deployFile('docs/DEPLOY.md').deployFile('scripts/deploy.sh');
    preg_match_all('/groibe:[a-z-]+/', $guide, $matches);

    $known = array_keys(Artisan::all());

    expect(array_unique($matches[0]))->not->toBeEmpty();

    foreach (array_unique($matches[0]) as $command) {
        expect($known)->toContain($command);
    }
});

it('names the scheduled jobs that really are scheduled, and the right number of them', function () {
    $guide = deployFile('docs/DEPLOY.md');
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

    // "shows fourteen jobs" in the smoke test must stay true (SCR-039 added four).
    expect(count(app(Schedule::class)->events()))->toBe(14)->and($guide)->toContain('shows fourteen jobs');

    foreach (['03:00', '03:10', '03:20', '03:30', '03:40', '03:45', '03:50', '08:00', '08:15', '08:30', '08:45', 'Sunday 02:30'] as $time) {
        expect($guide)->toContain($time);
    }

    expect($scheduled)->toContain('groibe:backup-db')->toContain('groibe:prune-email-log')->toContain('groibe:report-stale-invites')
        ->toContain('groibe:prune-queue-history')->toContain('groibe:prune-notifications')->toContain('groibe:prune-activity-log')->toContain('groibe:remind-data-requests');
});

it('gives only commands and addresses a person can paste, with no password in them', function () {
    $guide = deployFile('docs/DEPLOY.md');

    expect($guide)->not->toMatch('/(--password=|-p[A-Za-z0-9]{4,}\s|mysql[^\n]*-p\S+\s)/')
        ->and($guide)->not->toMatch('/(PASSWORD|SECRET)\s*=\s*[A-Za-z0-9]{6,}/')
        // The routes it asks a person to open exist.
        ->and(route('home'))->not->toBeEmpty();

    foreach (['/up', '/sitemap.xml', '/robots.txt', '/admin/login', '/login'] as $path) {
        expect($guide)->toContain($path);
    }

    $this->get('/up')->assertOk();
    $this->get('/sitemap.xml')->assertOk();
    $this->get('/robots.txt')->assertOk();
    $this->get('/login')->assertOk();
});
