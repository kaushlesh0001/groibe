<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Content seeded from copy that is not approved yet carries `needs_review` (SCR-021). Such a row can never be active,
 * however it is saved, and the `active` scope that every public read uses also leaves it out. An admin approves the
 * wording by clearing the flag, then switches the row on.
 *
 * @mixin Model
 */
trait GatedByReview
{
    public static function bootGatedByReview(): void
    {
        static::saving(function ($model): void {
            if ($model->needs_review) {
                $model->is_active = false;
            }
        });
    }

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true)->where($this->qualifyColumn('needs_review'), false);
    }
}
