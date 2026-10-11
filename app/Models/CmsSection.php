<?php

namespace App\Models;

use Database\Factories\CmsSectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** One homepage section, ordered and toggled in Admin, with JSON-backed content (spec 7.9, 10.1). */
class CmsSection extends Model
{
    /** @use HasFactory<CmsSectionFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['key', 'title', 'content', 'sort', 'is_active', 'needs_review'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'sort' => 'integer',
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['key', 'title', 'sort', 'is_active', 'needs_review'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @param Builder<CmsSection> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<CmsSection> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
