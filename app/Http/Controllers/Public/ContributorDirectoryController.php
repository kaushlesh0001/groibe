<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public contributor directory (spec 10.2). Every query goes through Profile::publiclyVisible(), so a
 * private, pending, rejected or suspended contributor cannot appear in a list, a search or a profile page.
 */
class ContributorDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $skill = $validated['skill'] ?? null;

        $profiles = Profile::query()
            ->publiclyVisible()
            ->with(['user.skills'])
            ->when($term !== '', fn (Builder $query) => $this->search($query, $term))
            ->when($skill !== null && $skill !== '', fn (Builder $query) => $query->whereHas(
                'user.skills',
                fn (Builder $skills) => $skills->where('skills.slug', $skill)->where('skills.is_active', true),
            ))
            ->orderByDesc('profiles.id')
            ->paginate((int) config('groibe.directory.per_page'))
            ->withQueryString();

        return view('public.contributors.index', [
            'profiles' => $profiles,
            'skills' => Skill::query()->active()->orderBy('name')->get(['id', 'name', 'slug']),
            'term' => $term,
            'skill' => $skill,
        ]);
    }

    public function show(string $slug): Response
    {
        $profile = Profile::query()->where('slug', $slug)->with(['user.skills'])->firstOrFail();

        // 404, not 403, so the existence of a private profile is not revealed. Owner and admins may still preview.
        abort_unless(Gate::allows('view', $profile), 404);

        $public = $profile->isPubliclyVisible();

        return response()
            ->view('public.contributors.show', ['profile' => $profile, 'public' => $public])
            ->header('X-Robots-Tag', $public ? 'index, follow' : 'noindex, nofollow');
    }

    public function photo(string $slug): StreamedResponse
    {
        $profile = Profile::query()->publiclyVisible()->where('slug', $slug)->firstOrFail();

        abort_if($profile->photo_path === null || ! Storage::disk('local')->exists($profile->photo_path), 404);

        return Storage::disk('local')->response($profile->photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** FULLTEXT on headline and bio (spec 7.10) on MySQL, a LIKE match elsewhere (tests run on SQLite). */
    private function search(Builder $query, string $term): void
    {
        if (DB::getDriverName() === 'mysql') {
            $query->whereFullText(['profiles.headline', 'profiles.bio'], $term);

            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $inner) => $inner->where('profiles.headline', 'like', $like)->orWhere('profiles.bio', 'like', $like));
    }
}
