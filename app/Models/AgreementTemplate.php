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

    /**
     * The one marker every seeded placeholder body carries, the SOW template's included (SCR-036, spec 8). A template whose
     * body still contains it is not real wording and counts as not published.
     */
    public const PLACEHOLDER_NOTE = 'Replace with the approved legal text.';

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

    /**
     * True while the body still carries the placeholder marker (spec 8, SCR-036). Placeholder text never reaches a visitor
     * and can never be accepted or used: every public page and every acceptance goes through `currentPublishedFor()`.
     */
    public function isPlaceholder(): bool
    {
        return str_contains((string) $this->body_html, self::PLACEHOLDER_NOTE);
    }

    /**
     * The single active template of a type, or null when none has been published. It may still be a placeholder, so only the
     * Agreement Templates admin screen uses it, to open the placeholder for replacement. Everything else uses
     * `currentPublishedFor()`.
     */
    public static function currentFor(AgreementType $type): ?self
    {
        return static::query()->active()->where('type', $type)->orderByDesc('version')->first();
    }

    /** The active template of a type that holds real wording, or null while none is published (SCR-036). */
    public static function currentPublishedFor(AgreementType $type): ?self
    {
        $template = static::currentFor($type);

        return $template !== null && ! $template->isPlaceholder() ? $template : null;
    }
}
