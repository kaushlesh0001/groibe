<?php

namespace App\Models;

use App\Enums\FeaturedItemType;
use Database\Factories\FeaturedItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An admin pick for a homepage section (spec 7.9). `item_id` points at a row of the table named by `type`, with no
 * foreign key: the public read resolves it every time and skips anything missing or not public any more.
 */
class FeaturedItem extends Model
{
    /** @use HasFactory<FeaturedItemFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['type', 'item_id', 'sort', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => FeaturedItemType::class,
            'item_id' => 'integer',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'item_id', 'sort', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @param Builder<FeaturedItem> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<FeaturedItem> $query */
    public function scopeOfType(Builder $query, FeaturedItemType $type): void
    {
        $query->where('type', $type->value);
    }

    /** @param Builder<FeaturedItem> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
