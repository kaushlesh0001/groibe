<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * CMS pages at /{slug} (spec 10.2). This is the fallback route, so it runs only when no real route matched, which keeps
 * every app, auth and public address safe from a page with the same slug. One path segment only, and an inactive or
 * unreviewed page answers 404.
 */
class PageController extends Controller
{
    public function __invoke(Request $request): View
    {
        $slug = $request->path();

        abort_unless($request->isMethod('GET') && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1, 404);

        return StaticPageController::cms($slug) ?? abort(404);
    }
}
