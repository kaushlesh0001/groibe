<?php

namespace App\Models;

use App\Enums\FaqAudience;
use Database\Factories\FaqFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A question and answer, grouped by audience (spec 7.9, 10.1). */
class Faq extends Model
{
    /** @use HasFactory<FaqFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['audience', 'question', 'answer', 'sort', 'is_active', 'needs_review'];

    protected function casts(): array
    {
        return [
            'audience' => FaqAudience::class,
            'sort' => 'integer',
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['audience', 'question', 'answer', 'sort', 'is_active', 'needs_review'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @param Builder<Faq> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<Faq> $query */
    public function scopeForAudience(Builder $query, FaqAudience $audience): void
    {
        $query->where('audience', $audience->value);
    }

    /** @param Builder<Faq> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
