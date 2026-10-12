<?php

use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Http\Middleware\EnsureActiveContributor;
use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Http\Middleware\EnsureUserRole;
use App\Models\Profile;
use App\Models\User;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;

/*
 * Review Prompt, Pass 9: every route is classified, and every classification is checked for what it promises.
 * A new route that is none of the known kinds fails the first test until someone decides what it is. Public routes must be
 * listed here on purpose, so a route can never become public by accident.
 */

/** URIs that anyone may open, with no sign-in. Each was chosen on purpose (spec 10.2, 16, 17.2). */
const PUBLIC_ROUTES = [
    // The public site, spec 10.2.
    '/', 'about', 'contact', 'contributors', 'contributors/{slug}', 'contributors/{slug}/photo', 'faq', 'for-clients', 'for-contributors',
    'how-it-works', 'opportunities', 'opportunities/{slug}', 'organizations', 'organizations/{slug}', 'privacy', 'terms', 'robots.txt',
    'sitemap.xml', 'skills/{slug}', 'work', 'work/{slug}', '{fallbackPlaceholder}',
    // Signing in, signing up and recovering an account, spec 16.
    'login', 'register', 'register/client', 'register/contributor', 'forgot-password', 'reset-password/{token}', 'invite/{token}', 'logout',
    // Health check, and the framework's own asset and endpoint routes (Flux, Livewire).
    'up',
];

/** URI prefixes of framework and package routes that are not ours: assets, and the Livewire and Filament system endpoints. */
const FRAMEWORK_PREFIXES = ['flux/', 'livewire-', 'filament/'];

/** Panel routes an admin must be able to open before two-factor set-up is finished, or while signing in. */
const ADMIN_BEFORE_TWO_FACTOR = ['admin/login', 'admin/multi-factor-authentication/set-up', 'admin/logout', 'admin/multi-factor-authentication/set-up-required'];

function routeKind(Route $route): string
{
    $uri = $route->uri();

    if (in_array($uri, PUBLIC_ROUTES, true)) {
        return 'public';
    }

    foreach (FRAMEWORK_PREFIXES as $prefix) {
        if (str_starts_with($uri, $prefix)) {
            return 'framework';
        }
    }

    return match (true) {
        str_starts_with($uri, 'app/client') => 'client',
        str_starts_with($uri, 'app/contributor') => 'contributor',
        $uri === 'admin' || str_starts_with($uri, 'admin/') => 'admin',
        default => 'authenticated',
    };
}

/** @return Collection<int, Route> */
function allRoutes(): Collection
{
    return collect(app('router')->getRoutes()->getRoutes());
}

/** The middleware that will really run for the route, as class names (aliases and groups resolved). @return list<string> */
function middlewareOf(Route $route): array
{
    return array_map(fn ($middleware) => is_string($middleware) ? $middleware : $middleware::class, app('router')->gatherRouteMiddleware($route));
}

function hasMiddleware(Route $route, string $class, ?string $argument = null): bool
{
    foreach (middlewareOf($route) as $middleware) {
        [$name, $arguments] = array_pad(explode(':', $middleware, 2), 2, null);

        if (is_a($name, $class, true) && ($argument === null || $arguments === $argument)) {
            return true;
        }
    }

    return false;
}

/** A URL for the route, with a harmless value in every placeholder. */
function dummyUrl(Route $route): string
{
    return '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
}

/** @return Collection<int, Route> routes a browser can open with GET, outside the framework */
function pageRoutes(): Collection
{
    return allRoutes()->filter(fn (Route $route) => in_array('GET', $route->methods(), true) && routeKind($route) !== 'framework');
}

describe('every route is accounted for', function () {
    it('has a known kind, and the public kind is a short list kept on purpose', function () {
        $public = allRoutes()->filter(fn (Route $route) => routeKind($route) === 'public' && ! str_starts_with($route->uri(), 'admin'));
        $unclassified = allRoutes()
            ->filter(fn (Route $route) => routeKind($route) === 'authenticated' && ! hasMiddleware($route, Authenticate::class))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri());

        expect($unclassified->values()->all())->toBe([])
            ->and($public->count())->toBeGreaterThan(20);
    });

    it('lets no route outside the public list be opened without signing in', function () {
        $open = allRoutes()
            ->reject(fn (Route $route) => in_array(routeKind($route), ['public', 'framework'], true))
            ->reject(fn (Route $route) => $route->uri() === 'admin/login' || in_array($route->uri(), ADMIN_BEFORE_TWO_FACTOR, true))
            ->reject(fn (Route $route) => hasMiddleware($route, Authenticate::class) || hasMiddleware($route, FilamentAuthenticate::class))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri());

        expect($open->values()->all())->toBe([]);
    });

    it('protects every client route with the client role, every contributor route with the contributor role', function () {
        $wrong = allRoutes()->filter(fn (Route $route) => match (routeKind($route)) {
            'client' => ! hasMiddleware($route, EnsureUserRole::class, 'client'),
            'contributor' => ! hasMiddleware($route, EnsureUserRole::class, 'contributor'),
            default => false,
        })->map(fn (Route $route) => $route->uri());

        expect($wrong->values()->all())->toBe([]);
    });

    it('puts the active-contributor check on every contributor route that handles work, money or applications', function () {
        $open = ['onboarding', 'profile', 'profile/photo', 'messages', 'messages/project/{project}', 'messages/{thread}'];

        $unchecked = allRoutes()
            ->filter(fn (Route $route) => routeKind($route) === 'contributor')
            ->reject(fn (Route $route) => in_array(str_replace('app/contributor/', '', $route->uri()), $open, true) || $route->uri() === 'app/contributor')
            ->reject(fn (Route $route) => hasMiddleware($route, EnsureActiveContributor::class))
            ->map(fn (Route $route) => $route->uri());

        expect($unchecked->values()->all())->toBe([]);
    });

    it('asks every admin page except sign-in and set-up for two-factor authentication', function () {
        $missing = allRoutes()
            ->filter(fn (Route $route) => routeKind($route) === 'admin')
            ->reject(fn (Route $route) => in_array($route->uri(), ADMIN_BEFORE_TWO_FACTOR, true) || str_starts_with($route->uri(), 'admin/multi-factor'))
            ->reject(fn (Route $route) => hasMiddleware($route, EnsureAdminTwoFactor::class))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri());

        expect($missing->values()->all())->toBe([]);
    });

    it('guards every route that changes data against cross-site requests', function () {
        $unguarded = allRoutes()
            ->filter(fn (Route $route) => array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [])
            ->reject(fn (Route $route) => hasMiddleware($route, PreventRequestForgery::class)
                || in_array('web', $route->gatherMiddleware(), true))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri());

        expect($unguarded->values()->all())->toBe([]);
    });

    it('requires a signature on every link that is handed out by email or for a file', function (string $uri) {
        $route = allRoutes()->first(fn (Route $route) => $route->uri() === $uri);

        expect(hasMiddleware($route, ValidateSignature::class))->toBeTrue();
    })->with(['invite/{token}', 'verify-email/{id}/{hash}', 'files/{media}']);

    it('answers 404 on the package download routes this app does not use', function () {
        $admin = adminUser();

        $this->actingAs($admin)->get('/filament/imports/1/failed-rows/download')->assertNotFound();
        $this->actingAs($admin)->get('/filament/exports/1/download')->assertNotFound();
    });

    it('does not serve the private disk through Laravel\'s own storage route', function () {
        expect(config('filesystems.disks.local.serve'))->toBeFalse()
            ->and(allRoutes()->contains(fn (Route $route) => str_starts_with($route->uri(), 'storage/')))->toBeFalse();
    });
});

describe('what each kind of route does for the wrong person', function () {
    it('sends a visitor to sign in, or refuses, on every route that is not public', function () {
        $leaks = [];

        foreach (pageRoutes()->reject(fn (Route $route) => routeKind($route) === 'public' || str_starts_with($route->uri(), 'admin/login')) as $route) {
            $status = $this->get(dummyUrl($route))->status();

            if (! in_array($status, [302, 401, 403, 404], true)) {
                $leaks[] = "{$status} ".dummyUrl($route);
            }
        }

        expect($leaks)->toBe([]);
    });

    it('keeps a client out of contributor pages and the admin, and a contributor out of client pages and the admin', function () {
        $leaks = [];

        foreach ([[clientUser(), ['contributor', 'admin']], [contributorUser(), ['client', 'admin']]] as [$user, $forbidden]) {
            asUser($user);

            foreach (pageRoutes()->filter(fn (Route $route) => in_array(routeKind($route), $forbidden, true)) as $route) {
                $status = $this->get(dummyUrl($route))->status();

                if (! in_array($status, [302, 403, 404], true)) {
                    $leaks[] = "{$status} ".dummyUrl($route).' as '.$user->role->value;
                }
            }
        }

        expect($leaks)->toBe([]);
    });

    it('keeps a contributor who is not active out of the pages for working, applying and portfolio', function () {
        $user = contributorUser();
        Profile::factory()->inState(OnboardingState::PendingReview)->create(['user_id' => $user->id]);
        asUser($user);
        $leaks = [];

        foreach (pageRoutes()->filter(fn (Route $route) => routeKind($route) === 'contributor' && hasMiddleware($route, EnsureActiveContributor::class)) as $route) {
            $status = $this->get(dummyUrl($route))->status();

            if (! in_array($status, [302, 403, 404], true)) {
                $leaks[] = "{$status} ".dummyUrl($route);
            }
        }

        expect($leaks)->toBe([])->and(pageRoutes()->filter(fn (Route $route) => hasMiddleware($route, EnsureActiveContributor::class))->count())->toBeGreaterThan(8);
    });

    it('keeps a suspended client out of every client page and the admin', function () {
        $user = clientUser();
        $user->forceFill(['status' => UserStatus::Suspended])->save();
        asUser($user);
        $leaks = [];

        foreach (pageRoutes()->filter(fn (Route $route) => in_array(routeKind($route), ['client', 'admin'], true) && ! str_starts_with($route->uri(), 'admin/login')) as $route) {
            $status = $this->get(dummyUrl($route))->status();

            if (! in_array($status, [302, 403, 404], true)) {
                $leaks[] = "{$status} ".dummyUrl($route);
            }
        }

        expect($leaks)->toBe([]);
    });

    it('keeps a suspended contributor out of every page that handles work, money or applications, and leaves them the rest by design', function () {
        $user = User::factory()->contributor()->suspended()->create();
        Profile::factory()->inState(OnboardingState::Active)->create(['user_id' => $user->id]);
        asUser($user);
        $leaks = [];

        foreach (pageRoutes()->filter(fn (Route $route) => (routeKind($route) === 'contributor' && hasMiddleware($route, EnsureActiveContributor::class)) || routeKind($route) === 'admin') as $route) {
            $status = $this->get(dummyUrl($route))->status();

            if (! in_array($status, [302, 403, 404], true)) {
                $leaks[] = "{$status} ".dummyUrl($route);
            }
        }

        expect($leaks)->toBe([]);
    });

    it('keeps an admin out of the contributor and client apps', function () {
        asUser(adminUser());
        $leaks = [];

        foreach (pageRoutes()->filter(fn (Route $route) => in_array(routeKind($route), ['contributor', 'client'], true)) as $route) {
            $status = $this->get(dummyUrl($route))->status();

            if (! in_array($status, [302, 403, 404], true)) {
                $leaks[] = "{$status} ".dummyUrl($route);
            }
        }

        expect($leaks)->toBe([]);
    });
});
