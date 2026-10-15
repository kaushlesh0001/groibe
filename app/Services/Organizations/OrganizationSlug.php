<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use Illuminate\Support\Str;

/** A unique organization slug from a name, counting soft-deleted organizations so a slug is never reused. */
class OrganizationSlug
{
    public function unique(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;

        for ($i = 2; Organization::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
