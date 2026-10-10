<?php

namespace App\Models;

use Database\Factories\ProjectTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProjectType extends Model
{
    /** @use HasFactory<ProjectTypeFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'slug', 'description', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @param Builder<ProjectType> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
