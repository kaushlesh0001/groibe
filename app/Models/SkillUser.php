<?php

namespace App\Models;

use App\Enums\SkillLevel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** A contributor's skill with level and admin verification (spec 7.2). */
class SkillUser extends Pivot
{
    public $incrementing = true;

    protected $table = 'skill_user';

    /** @var list<string> */
    protected $fillable = ['user_id', 'skill_id', 'level', 'years', 'is_verified', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return [
            'level' => SkillLevel::class,
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
