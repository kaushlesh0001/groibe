<?php

namespace App\Models;

use Database\Factories\TestimonialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A quote shown on the homepage (spec 7.9, 10.1). Only real, approved quotes are ever made active. */
class Testimonial extends Model
{
    /** @use HasFactory<TestimonialFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['author_name', 'author_title', 'organization_name', 'quote', 'photo_path', 'is_active', 'needs_review', 'sort'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['author_name', 'author_title', 'organization_name', 'quote', 'is_active', 'needs_review', 'sort'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @param Builder<Testimonial> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<Testimonial> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
