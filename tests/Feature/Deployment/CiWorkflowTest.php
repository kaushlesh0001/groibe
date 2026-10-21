<?php

/**
 * The CI workflow is code too. These tests keep it to the rules in CLAUDE.md, so it cannot drift back to what made the old
 * workflows fail on every push (`phpunit` instead of Pest, PHP 8.4, Pint on the whole repo).
 */
function ciFile(): string
{
    return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
}

it('is the only workflow, so nothing else runs on a push', function () {
    $files = array_map('basename', glob(base_path('.github/workflows/*')) ?: []);

    expect($files)->toBe(['ci.yml']);
});

it('runs on PHP 8.3 with no coverage, the version in composer.json and on the host', function () {
    $ci = ciFile();

    expect($ci)->toContain("php-version: '8.3'")
        ->and($ci)->toContain('coverage: none')
        ->and($ci)->not->toContain('8.4')
        ->and($ci)->not->toContain('xdebug')
        ->and(json_decode((string) file_get_contents(base_path('composer.json')), true)['config']['platform']['php'])->toStartWith('8.3');
});

it('builds the assets before it runs any test', function () {
    $ci = ciFile();

    expect($ci)->toContain('npm ci')->and($ci)->not->toContain('npm i ')->and($ci)->not->toContain('npm install');

    $check = substr($ci, (int) strpos($ci, "\n  check:"), (int) strpos($ci, "\n  plan:") - (int) strpos($ci, "\n  check:"));
    expect(strpos($check, 'npm run build'))->toBeLessThan(strpos($check, 'vendor/bin/pest'));
});

it('runs Pest one folder per call with the memory limit, and never artisan test or phpunit', function () {
    $ci = ciFile();

    preg_match_all('/run: (php -d memory_limit=1G vendor\/bin\/pest [^\n]+)/', $ci, $calls);

    expect($calls[1])->not->toBeEmpty()
        ->and($ci)->not->toContain('artisan test')
        ->and($ci)->not->toContain('vendor/bin/phpunit');

    foreach ($calls[1] as $call) {
        expect($call)->toContain('php -d memory_limit=1G vendor/bin/pest');
    }

    // Each quick call names a path of its own; none runs the whole suite.
    foreach (['tests/Feature/Policies', 'tests/Feature/Security', 'tests/Feature/Ops', 'tests/Feature/Deployment/HttpsRedirectTest.php'] as $path) {
        expect($ci)->toContain($path);
    }
});

it('runs Pint only with --test on explicit paths, never on the whole repo or docs/site-content', function () {
    $ci = ciFile();

    expect($ci)->toContain('vendor/bin/pint --test app database routes config tests scripts')
        ->and($ci)->not->toContain('site-content');

    preg_match_all('/vendor\/bin\/pint[^\n]*/', $ci, $calls);

    foreach ($calls[0] as $call) {
        expect($call)->toContain('--test')->and(trim($call))->not->toBe('vendor/bin/pint');
    }
});

it('runs Larastan with the memory limit', function () {
    expect(ciFile())->toContain('vendor/bin/phpstan analyse --memory-limit=1G');
});

it('skips docs-only pushes, cancels superseded runs, and times out', function () {
    $ci = ciFile();

    expect($ci)->toContain("- 'docs/**'")
        ->and($ci)->toContain('cancel-in-progress: true')
        ->and($ci)->toContain('timeout-minutes: 15')
        ->and($ci)->toContain('workflow_dispatch');
});

it('runs the full suite by hand as a matrix, one job per test folder', function () {
    $ci = ciFile();

    expect($ci)->toContain('fromJSON(needs.plan.outputs.targets)')
        ->and($ci)->toContain('tests/Feature/*/')
        ->and($ci)->toContain('fail-fast: false')
        ->and($ci)->toContain("github.event_name == 'workflow_dispatch'")
        ->and($ci)->toContain("github.event_name != 'workflow_dispatch'");
});

it('asks for no secret and no environment, and has the least permission', function () {
    $ci = ciFile();

    expect($ci)->not->toContain('secrets.')
        ->and($ci)->not->toContain('FLUX')
        ->and($ci)->not->toContain('environment:')
        ->and($ci)->not->toContain('contents: write')
        ->and($ci)->toContain('contents: read');
});
