<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ProofOfWork;
use App\Models\Skill;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public proof of work (spec 10.2, 9). Every query goes through ProofOfWork::publiclyVisible(), so a draft, pending,
 * hidden, private or members-only item, or one whose project consent is `none`, cannot appear in a list, a search or a
 * page. The `proof_of_work_public` flag is enforced by the route group. The client is named only under `named` consent.
 */
class WorkController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $skill = $validated['skill'] ?? null;

        $items = ProofOfWork::query()
            ->publiclyVisible()
            ->with(['user.profile', 'skills:id,name,slug', 'project.organization:id,name,industry'])
            ->when($term !== '', fn (Builder $query) => $this->search($query, $term))
            ->when($skill !== null && $skill !== '', fn (Builder $query) => $query->whereHas(
                'skills',
                fn (Builder $skills) => $skills->where('skills.slug', $skill)->where('skills.is_active', true),
            ))
            ->orderByDesc('proof_of_work.published_at')
            ->orderByDesc('proof_of_work.id')
            ->paginate((int) config('groibe.directory.per_page'))
            ->withQueryString();

        return view('public.work.index', [
            'items' => $items,
            'skills' => Skill::query()->active()->orderBy('name')->get(['id', 'name', 'slug']),
            'term' => $term,
            'skill' => $skill,
        ]);
    }

    public function show(string $slug): Response
    {
        $item = ProofOfWork::query()
            ->publiclyVisible()
            ->where('proof_of_work.slug', $slug)
            ->with(['user.profile', 'skills:id,name,slug', 'project.organization:id,name,industry'])
            ->firstOrFail();

        return response()
            ->view('public.work.show', ['item' => $item])
            ->header('X-Robots-Tag', 'index, follow');
    }

    /** FULLTEXT on title and summary (spec 7.10) on MySQL, a LIKE match elsewhere (tests run on SQLite). */
    private function search(Builder $query, string $term): void
    {
        if (DB::getDriverName() === 'mysql') {
            $query->whereFullText(['proof_of_work.title', 'proof_of_work.summary'], $term);

            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $inner) => $inner->where('proof_of_work.title', 'like', $like)->orWhere('proof_of_work.summary', 'like', $like));
    }
}
