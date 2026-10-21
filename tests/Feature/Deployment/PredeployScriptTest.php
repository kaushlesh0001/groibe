<?php

/**
 * SCR-037 (spec 17.4): `scripts/deploy.sh` takes a pre-deploy dump before the site goes into maintenance mode, and stops if the
 * command fails or the file is missing, empty or under 10 KB. The structure is pinned here. The behaviour is rehearsed with a stand-in
 * `artisan` where bash is available (the same way the Pass 10 script was exercised); it has not been run on the real host.
 */
function deployScript(): string
{
    return (string) file_get_contents(base_path('scripts/deploy.sh'));
}

/** The body of deploy(). */
function deployBody(): string
{
    preg_match('/^deploy\(\) \{\R(.*?)\R\}\R/ms', deployScript(), $match);

    return $match[1] ?? '';
}

function bashPath(): ?string
{
    $found = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where bash 2>NUL' : 'command -v bash'));

    return $found === '' ? null : (string) strtok($found, "\r\n");
}

it('takes the backup inside deploy(), after the checks and before the site goes into maintenance mode', function () {
    $deploy = deployBody();
    $backup = strpos($deploy, 'predeploy_backup');
    $down = strpos($deploy, 'artisan down --retry');
    $preflight = strpos($deploy, 'preflight');

    expect($backup)->not->toBeFalse()
        ->and($down)->not->toBeFalse()
        ->and($backup)->toBeGreaterThan($preflight)
        ->and($backup)->toBeLessThan($down)
        ->and($backup)->toBeLessThan((int) strpos($deploy, 'migrate --force'))
        ->and($backup)->toBeLessThan((int) strpos($deploy, 'git pull --ff-only'));
});

it('does not run the backup in rollback or check, and sets no failure trap before it', function () {
    $script = deployScript();
    preg_match('/^rollback\(\) \{\R(.*?)\R\}\R/ms', $script, $match);

    expect($match[1] ?? '')->not->toContain('predeploy_backup');
    expect(strpos($script, 'trap on_failure ERR'))->toBeGreaterThan((int) strpos($script, 'predeploy_backup'));
    // A failed backup leaves the site up: it must use fail() (exit 1, no maintenance mode), not the on_failure trap.
    expect(deployBody())->not->toMatch('/predeploy_backup[\s\S]*?trap on_failure ERR[\s\S]*?predeploy_backup/');
});

it('asks for the pre-deploy kind of dump and checks the file itself, not only the exit code', function () {
    $script = deployScript();

    expect($script)->toContain('groibe:backup-db --predeploy')
        ->and($script)->toContain('MIN_DUMP_BYTES=10240')
        ->and($script)->toContain('BACKUP_FILE=')
        ->and($script)->toContain('-f "$file"')
        ->and($script)->toContain('wc -c')
        ->and($script)->toContain('-ge "$MIN_DUMP_BYTES"')
        ->and($script)->toContain('-eq 2');   // the first-deploy code, skipped
});

it('says in plain words that nothing changed and the site is still up when the backup stops it', function () {
    $script = deployScript();

    expect(substr_count($script, 'Nothing was changed and the site is still up'))->toBe(3)   // command failed, file missing, file too small
        ->and($script)->toContain('The database has no tables yet');
});

it('has no switch to skip the backup', function () {
    expect(strtolower(deployScript()))->not->toContain('skip_db_backup')->not->toContain('skip_backup')->not->toContain('no-backup');
});

it('keeps Unix line endings after the edit', function () {
    expect(deployScript())->not->toContain("\r");
});

describe('rehearsed with a stand-in artisan', function () {
    function rehearse(string $scenario): array
    {
        $bash = bashPath();
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'groibe-rehearse-'.uniqid();
        mkdir($dir);

        // The function text exactly as it is in the script.
        preg_match('/^MIN_DUMP_BYTES=.*?(?=^deploy\(\) \{)/ms', deployScript(), $function);
        file_put_contents($dir.'/fn.sh', str_replace("\r", '', $function[0]));

        $file = $dir.'/dump.sql.gz';
        file_put_contents($dir.'/run.sh', <<<BASH
#!/usr/bin/env bash
set -euo pipefail
say() { printf '\\n==> %s\\n' "\$*"; }
fail() { printf '\\nERROR: %s\\n' "\$*" >&2; exit 1; }
source "$dir/fn.sh"
FILE="$file"
artisan() {
  case "$scenario" in
    ok) head -c 20000 /dev/zero > "\$FILE"; echo "Wrote it"; echo "BACKUP_FILE=\$FILE"; return 0 ;;
    first) echo "no tables"; return 2 ;;
    fail) echo "disk full"; return 1 ;;
    missing) rm -f "\$FILE"; echo "BACKUP_FILE=\$FILE"; return 0 ;;
    empty) : > "\$FILE"; echo "BACKUP_FILE=\$FILE"; return 0 ;;
    small) head -c 5000 /dev/zero > "\$FILE"; echo "BACKUP_FILE=\$FILE"; return 0 ;;
    exactly) head -c 10240 /dev/zero > "\$FILE"; echo "BACKUP_FILE=\$FILE"; return 0 ;;
    noline) echo "Wrote it"; return 0 ;;
  esac
}
predeploy_backup
echo CONTINUED
BASH);

        exec(escapeshellarg((string) $bash).' '.escapeshellarg($dir.'/run.sh').' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    beforeEach(function () {
        if (bashPath() === null) {
            $this->markTestSkipped('bash is not available here');
        }
    });

    it('goes on after a good dump', function () {
        [$status, $out] = rehearse('ok');

        expect($status)->toBe(0)->and($out)->toContain('Pre-deploy backup written')->toContain('CONTINUED');
    });

    it('accepts a dump of exactly 10 KB', function () {
        [$status, $out] = rehearse('exactly');

        expect($status)->toBe(0)->and($out)->toContain('CONTINUED');
    });

    it('skips on a first deploy with no tables and goes on', function () {
        [$status, $out] = rehearse('first');

        expect($status)->toBe(0)->and($out)->toContain('first deploy')->toContain('CONTINUED');
    });

    it('stops when the command fails', function () {
        [$status, $out] = rehearse('fail');

        expect($status)->toBe(1)->and($out)->toContain('failed')->not->toContain('CONTINUED');
    });

    it('stops when the file is missing, empty, under 10 KB, or never reported', function (string $scenario, string $words) {
        [$status, $out] = rehearse($scenario);

        expect($status)->toBe(1)->and($out)->toContain($words)->not->toContain('CONTINUED');
    })->with([
        'missing' => ['missing', 'file is missing'],
        'empty' => ['empty', '0 bytes'],
        'small' => ['small', '5000 bytes'],
        'no file line' => ['noline', 'file is missing'],
    ]);
});
