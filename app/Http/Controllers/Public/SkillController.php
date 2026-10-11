<?php

namespace App\Http\Controllers\Public;

use App\Enums\FeatureFlag;
use App\Http\Controllers\Controller;
use App\Models\OpenRole;
use App\Models\Profile;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

/**
 * A public skill page (spec 10.2): the open roles, contributors and proof of work for one skill. Every list goes through
 * the same visibility rule as its own directory, and a list whose flag is off is left out. An inactive skill answers 404.
 */
class SkillController extends Controller
{
    private const LIMIT = 12;

    public function show(string $slug, FeatureFlags $flags): Response
    {
        $skill = Skill::query()->active()->where('slug', $slug)->with('category:id,name')->firstOrFail();

        $roles = $flags->enabled(FeatureFlag::OpenRoles)
            ? OpenRole::query()->visibleTo(null)->whereHas('skills', fn (Builder $q) => $q->whereKey($skill->getKey()))
                ->with(['skills', 'projectType'])->orderByDesc('open_roles.published_at')->limit(self::LIMIT)->get()
            : collect();

        $profiles = $flags->enabled(FeatureFlag::PublicDirectories)
            ? Profile::query()->publiclyVisible()->whereHas('user.skills', fn (Builder $q) => $q->where('skills.id', $skill->getKey()))
                ->with('user.skills')->orderByDesc('profiles.id')->limit(self::LIMIT)->get()
            : collect();

        $proofs = $flags->enabled(FeatureFlag::ProofOfWorkPublic)
            ? ProofOfWork::query()->publiclyVisible()->whereHas('skills', fn (Builder $q) => $q->where('skills.id', $skill->getKey()))
                ->with(['user.profile', 'project.organization:id,name,industry'])->orderByDesc('proof_of_work.published_at')->limit(self::LIMIT)->get()
            : collect();

        return response()
            ->view('public.skills.show', ['skill' => $skill, 'roles' => $roles, 'profiles' => $profiles, 'proofs' => $proofs])
            // An empty skill page is thin content: kept out of search and out of the sitemap.
            ->header('X-Robots-Tag', $roles->isEmpty() && $profiles->isEmpty() && $proofs->isEmpty() ? 'noindex, follow' : 'index, follow');
    }
}
