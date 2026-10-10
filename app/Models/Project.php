<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Models\Concerns\HasUuid;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Project extends Model implements HasMedia
{
    public const FILES = 'project_files';

    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuid, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'brief_id',
        'organization_id',
        'code',
        'title',
        'slug',
        'summary',
        'scope_summary',
        'project_type_id',
        'status',
        'contract_value',
        'currency',
        'start_date',
        'due_date',
        'delivery_manager_id',
        'client_can_view_shortlist',
        'showcase_consent',
        'zoho_invoice_ref',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'showcase_consent' => ShowcaseConsent::class,
            'contract_value' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'client_can_view_shortlist' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'title', 'status', 'contract_value', 'currency', 'start_date', 'due_date', 'delivery_manager_id', 'client_can_view_shortlist', 'showcase_consent'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** Private by default: the `local` disk is never web-served (spec 17.1). */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FILES)->useDisk('local');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Brief, $this> */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    /** @return BelongsTo<ProjectType, $this> */
    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deliveryManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_manager_id');
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'project_skills')->withTimestamps();
    }

    /** @return HasMany<Sow, $this> */
    public function sows(): HasMany
    {
        return $this->hasMany(Sow::class);
    }

    /** @param Builder<Project> $query */
    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    /** Latest SOW version that is not superseded. */
    public function currentSow(): ?Sow
    {
        return $this->sows()->where('status', '!=', 'superseded')->orderByDesc('version')->first();
    }
}
