<?php

namespace App\Models;

use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'category_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'slug', 'category_id', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<User, $this, SkillUser, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(SkillUser::class)->withPivot(['level', 'years', 'is_verified', 'verified_by', 'verified_at'])->withTimestamps();
    }

    /** @param Builder<Skill> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
