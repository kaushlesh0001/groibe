<?php

namespace App\Services\Projects;

use App\Models\Project;

/** Produces the next GRB-YYYY-NNNN code. Uniqueness is enforced by the database; callers retry on collision. */
class ProjectCodeGenerator
{
    public function next(?int $year = null): string
    {
        $prefix = 'GRB-'.($year ?? now()->year).'-';

        // Longest-then-greatest ordering keeps numbers correct past 9999. Soft-deleted projects still hold their code.
        $last = Project::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->value('code');

        $number = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
