<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public organizations (spec 10.2). Only organizations that are verified and set to public are listed or readable, and
 * everything else answers 404, so a private organization is never revealed. The `public_directories` flag is enforced
 * by the route group.
 */
class OrganizationDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($validated['q'] ?? ''));

        $organizations = Organization::query()
            ->public()
            ->when($term !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->paginate((int) config('groibe.directory.per_page'))
            ->withQueryString();

        return view('public.organizations.index', ['organizations' => $organizations, 'term' => $term]);
    }

    public function show(string $slug): Response
    {
        $organization = Organization::query()->public()->where('slug', $slug)->firstOrFail();

        return response()
            ->view('public.organizations.show', ['organization' => $organization])
            ->header('X-Robots-Tag', 'index, follow');
    }
}
