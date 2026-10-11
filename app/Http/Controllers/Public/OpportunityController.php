<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\OpenRole;
use App\Services\OpenRoles\OpenRoleSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public opportunities (spec 10.2). Every query goes through OpenRole::visibleTo(null), so a draft, closed, filled,
 * archived, members-only or invite-only role cannot appear in a list, a search or a detail page, whoever is logged in.
 * The `open_roles` flag is enforced by the route group.
 */
class OpportunityController extends Controller
{
    public function __construct(private OpenRoleSearch $search) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(OpenRoleSearch::rules());
        $visible = OpenRole::query()->visibleTo(null);

        $roles = $this->search->apply(OpenRole::query()->visibleTo(null), $filters)
            ->with(['skills', 'projectType'])
            ->orderByDesc('open_roles.published_at')
            ->orderByDesc('open_roles.id')
            ->paginate((int) config('groibe.opportunities.per_page'))
            ->withQueryString();

        return view('public.opportunities.index', [
            'roles' => $roles,
            'filters' => $filters,
            'options' => $this->search->options($visible),
        ]);
    }

    public function show(string $slug): Response
    {
        $role = OpenRole::query()->visibleTo(null)->where('open_roles.slug', $slug)->with(['skills', 'projectType'])->firstOrFail();

        return response()
            ->view('public.opportunities.show', ['role' => $role])
            ->header('X-Robots-Tag', 'index, follow');
    }
}
