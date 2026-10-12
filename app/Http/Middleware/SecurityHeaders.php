<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web response (spec 17.2): HSTS on HTTPS, X-Frame-Options, X-Content-Type-Options,
 * Referrer-Policy, Permissions-Policy and a Content-Security-Policy.
 *
 * Two policies. The public marketing pages are server-rendered and need no scripts of their own, so a route inside the
 * `csp.strict` group gets a policy with no inline code and no `eval`. The signed-in apps, the sign-in pages and the admin panel
 * run Livewire, Alpine and Filament, which need inline scripts and `eval`, so they get the same origin rules with those two
 * allowed. Neither policy permits another origin for scripts, styles, fonts or frames.
 *
 * A header a controller already set is left alone.
 */
class SecurityHeaders
{
    /** The middleware name that marks a route group as strict. */
    public const STRICT = 'csp.strict';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers($request) as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            (config('groibe.security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy($this->isStrict($request)),
        ];

        $hsts = (int) config('groibe.security.hsts_max_age');

        if ($hsts > 0 && $request->isSecure()) {
            $headers['Strict-Transport-Security'] = "max-age={$hsts}; includeSubDomains";
        }

        return $headers;
    }

    private function isStrict(Request $request): bool
    {
        $middleware = $request->route()?->gatherMiddleware() ?? [];

        return in_array(self::STRICT, $middleware, true) || in_array(StrictContentSecurityPolicy::class, $middleware, true);
    }

    private function policy(bool $strict): string
    {
        $dev = $this->devServer();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $strict ? ["'self'"] : ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
            'style-src' => $strict ? ["'self'"] : ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', ...($strict ? [] : ['blob:'])],
            'font-src' => ["'self'", ...($strict ? [] : ['data:'])],
            'connect-src' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        if ($dev !== null) {
            foreach (['script-src', 'style-src', 'font-src', 'img-src'] as $name) {
                $directives[$name][] = $dev['http'];
            }

            $directives['connect-src'] = [...$directives['connect-src'], $dev['http'], $dev['ws']];
        }

        return implode('; ', array_map(fn (string $name, array $sources): string => $name.' '.implode(' ', $sources), array_keys($directives), $directives));
    }

    /**
     * While `npm run dev` is running locally, the page loads scripts and styles from the Vite server. That origin is allowed in
     * the local environment only, and only while Vite's `public/hot` file exists.
     *
     * @return array{http: string, ws: string}|null
     */
    private function devServer(): ?array
    {
        if (! app()->environment('local') || ! is_file(public_path('hot'))) {
            return null;
        }

        $host = parse_url(trim((string) file_get_contents(public_path('hot'))), PHP_URL_HOST);
        $port = parse_url(trim((string) file_get_contents(public_path('hot'))), PHP_URL_PORT);

        if (! is_string($host)) {
            return null;
        }

        $origin = $host.($port ? ":{$port}" : '');

        return ['http' => "http://{$origin}", 'ws' => "ws://{$origin}"];
    }
}
