<?php

namespace App\Services\Website;

use App\Enums\FeatureFlag;
use App\Models\OpenRole;
use App\Models\Profile;
use App\Models\ProofOfWork;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Support\Facades\DB;

/**
 * Which skills have something public to show: a role anyone can see, a public contributor or a consented proof-of-work
 * item, counting only the lists whose flag is on. Used to keep empty skill pages out of the sitemap.
 */
class PublicSkillIndex
{
    /** @return list<int> */
    public static function idsWithContent(FeatureFlags $flags): array
    {
        $ids = collect();

        if ($flags->enabled(FeatureFlag::OpenRoles)) {
            $ids = $ids->merge(DB::table('open_role_skills')->whereIn('open_role_id', OpenRole::query()->visibleTo(null)->select('open_roles.id'))->pluck('skill_id'));
        }

        if ($flags->enabled(FeatureFlag::PublicDirectories)) {
            $ids = $ids->merge(DB::table('skill_user')->whereIn('user_id', Profile::query()->publiclyVisible()->select('profiles.user_id'))->pluck('skill_id'));
        }

        if ($flags->enabled(FeatureFlag::ProofOfWorkPublic)) {
            $ids = $ids->merge(DB::table('proof_of_work_skill')->whereIn('proof_of_work_id', ProofOfWork::query()->publiclyVisible()->select('proof_of_work.id'))->pluck('skill_id'));
        }

        return $ids->unique()->values()->map(fn ($id): int => (int) $id)->all();
    }
}
