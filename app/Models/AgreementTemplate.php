<?php

namespace App\Models;

use App\Enums\AgreementType;
use Database\Factories\AgreementTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Versioned legal text (spec 7.7, 8). Legal text lives here, never in code. */
class AgreementTemplate extends Model
{
    /** @use HasFactory<AgreementTemplateFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['type', 'version', 'title', 'body_html', 'is_active', 'effective_from'];

    protected function casts(): array
    {
        return [
            'type' => AgreementType::class,
            'version' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    /** Wording is immutable once saved: acceptances point at it. Changes go through a new version. */
    protected static function booted(): void
    {
        static::updating(function (self $template): void {
            if ($template->isDirty(['type', 'version', 'title', 'body_html', 'effective_from'])) {
                throw new LogicException('A saved agreement version cannot be edited. Publish a new version instead.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'version', 'title', 'is_active', 'effective_from'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return HasMany<AgreementAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(AgreementAcceptance::class, 'template_id');
    }

    /** @param Builder<AgreementTemplate> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** The single active template of a type, or null when none has been published. */
    public static function currentFor(AgreementType $type): ?self
    {
        return static::query()->active()->where('type', $type)->orderByDesc('version')->first();
    }
}
