<?php

/**
 * SCR-038 (spec 17.1, 17.2): `public/.htaccess` sends plain HTTP to HTTPS and honours X-Forwarded-Proto. The redirect itself is
 * proven with `curl -I` on staging (DEPLOY.md, smoke test). These tests pin the rule text and its place in the file.
 */
function htaccess(): string
{
    return (string) file_get_contents(base_path('public/.htaccess'));
}

it('redirects when the request is not HTTPS and Cloudflare did not forward it as HTTPS', function () {
    $file = htaccess();

    expect($file)->toContain('RewriteCond %{HTTPS} !=on')
        ->and($file)->toContain('RewriteCond %{HTTP:X-Forwarded-Proto} !=https [NC]')
        ->and($file)->toContain('RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]');
});

it('puts both conditions directly before the rule, so they apply to it and to nothing else', function () {
    expect(htaccess())->toMatch(
        '/RewriteCond %\{HTTPS\} !=on\R\s*RewriteCond %\{HTTP:X-Forwarded-Proto\} !=https \[NC\]\R\s*RewriteRule \^ https:\/\/%\{HTTP_HOST\}%\{REQUEST_URI\} \[L,R=301\]/'
    );
});

it('never redirects the paths a certificate authority and cPanel AutoSSL fetch over plain HTTP', function () {
    $file = htaccess();
    $exempt = 'RewriteCond %{REQUEST_URI} !^/\.well-known/(cpanel-dcv|pki-validation|acme-challenge)/ [NC]';

    // Part of the same condition run as the redirect, so it applies to it, and it comes before the rule.
    expect($file)->toContain($exempt)
        ->and(strpos($file, $exempt))->toBeLessThan(strpos($file, 'RewriteCond %{HTTPS} !=on'));

    // The pattern lets the validation paths through and nothing else.
    $pattern = '#^/\.well-known/(cpanel-dcv|pki-validation|acme-challenge)/#i';
    foreach (['/.well-known/acme-challenge/abc_123', '/.well-known/pki-validation/ABCDEF.txt', '/.well-known/cpanel-dcv/token'] as $path) {
        expect(preg_match($pattern, $path))->toBe(1);
    }
    foreach (['/', '/login', '/admin', '/.well-known/security.txt', '/x/.well-known/acme-challenge/a'] as $path) {
        expect(preg_match($pattern, $path))->toBe(0);
    }
});

it('runs the redirect first: after the engine is on, before every other rule', function () {
    $file = htaccess();
    $engine = strpos($file, 'RewriteEngine On');
    $redirect = strpos($file, 'RewriteRule ^ https://');

    expect($engine)->not->toBeFalse()
        ->and($redirect)->not->toBeFalse()
        ->and($redirect)->toBeGreaterThan($engine);

    // Every other rewrite rule comes after it, including the front controller.
    foreach (['RewriteRule .* - [E=HTTP_AUTHORIZATION', 'RewriteRule ^ %1 [L,R=301]', 'RewriteRule ^ index.php [L]'] as $other) {
        expect(strpos($file, $other))->toBeGreaterThan($redirect, "{$other} must come after the HTTPS redirect");
    }

    // The first RewriteRule in the file is the redirect.
    preg_match_all('/^\s*RewriteRule\s/m', $file, $rules, PREG_OFFSET_CAPTURE);
    expect($rules[0][0][1])->toBeLessThanOrEqual($redirect);
});

it('stays one balanced rewrite block with no hard-coded host', function () {
    $file = htaccess();

    expect(substr_count($file, '<IfModule'))->toBe(substr_count($file, '</IfModule>'))
        ->and($file)->toContain('<IfModule mod_rewrite.c>')
        ->and(substr_count($file, 'RewriteEngine On'))->toBe(1)
        ->and($file)->not->toMatch('/https:\/\/(?!%\{HTTP_HOST\})[a-z0-9]/i');
});

it('uses a permanent redirect only once, for HTTPS, and keeps the other rules intact', function () {
    $file = htaccess();

    expect(substr_count($file, 'https://'))->toBe(1)
        ->and($file)->toContain('RewriteCond %{REQUEST_FILENAME} !-d')
        ->and($file)->toContain('RewriteRule ^ index.php [L]');
});
