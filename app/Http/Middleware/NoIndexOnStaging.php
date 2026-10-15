<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staging copy of the site is never indexed (spec 10.4, 17.1, SCR-035). While `APP_ENV` is `staging`, every web response
 * carries `X-Robots-Tag: noindex, nofollow`, replacing the `index, follow` some public controllers set. Production and local
 * are left alone. `robots.txt` and the sitemap follow the same rule through `applies()`.
 */
class NoIndexOnStaging
{
    public const ENVIRONMENT = 'staging';

    public static function applies(): bool
    {
        return app()->environment(self::ENVIRONMENT);
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (self::applies()) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
