<?php

namespace App\Models;

use App\Enums\BriefStatus;
use App\Enums\Confidentiality;
use App\Models\Concerns\HasUuid;
use Database\Factories\BriefFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Brief extends Model implements HasMedia
{
    public const FILES = 'brief_files';

    /** @use HasFactory<BriefFactory> */
    use HasFactory, HasUuid, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'submitted_by',
        'title',
        'problem',
        'desired_outcome',
        'project_type_id',
        'budget_band',
        'timeline_band',
        'target_start',
        'deadline',
        'confidentiality',
        'status',
        'needs_info_note',
        'internal_notes',
        'assigned_admin_id',
        'converted_project_id',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BriefStatus::class,
            'confidentiality' => Confidentiality::class,
            'target_start' => 'date',
            'deadline' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status', 'budget_band', 'timeline_band', 'confidentiality', 'assigned_admin_id', 'converted_project_id'])
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

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    /** @return BelongsTo<ProjectType, $this> */
    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function convertedProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'converted_project_id');
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'brief_skills')->withTimestamps();
    }

    /** @param Builder<Brief> $query */
    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }
}
