<?php

namespace App\Models;

use App\Enums\CompensationType;
use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Enums\WorkMode;
use App\Models\Concerns\HasUuid;
use Database\Factories\OpenRoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A published staffing slot contributors can apply to (spec 2.2, 6.4, 7.4). Applications are controlled by admins. */
class OpenRole extends Model
{
    /** @use HasFactory<OpenRoleFactory> */
    use HasFactory, HasUuid, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'title',
        'slug',
        'summary',
        'description',
        'tasks_list',
        'learning_outcomes',
        'project_type_id',
        'work_mode',
        'est_hours_min',
        'est_hours_max',
        'duration_label',
        'compensation_type',
        'compensation_note',
        'visibility',
        'positions_needed',
        'status',
        'deadline',
        'is_featured',
        'created_by',
        'published_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'tasks_list' => 'array',
            'learning_outcomes' => 'array',
            'work_mode' => WorkMode::class,
            'compensation_type' => CompensationType::class,
            'visibility' => OpenRoleVisibility::class,
            'status' => OpenRoleStatus::class,
            'positions_needed' => 'integer',
            'est_hours_min' => 'integer',
            'est_hours_max' => 'integer',
            'deadline' => 'date',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** Slugs are immutable once the role has been published (spec 16). */
    protected static function booted(): void
    {
        static::updating(function (self $role): void {
            if ($role->isDirty('slug') && $role->getOriginal('published_at') !== null) {
                throw new LogicException('The slug of a published open role cannot change.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status', 'visibility', 'positions_needed', 'is_featured', 'deadline', 'project_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectType, $this> */
    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'open_role_skills')->withTimestamps();
    }

    /** Contributors on the SCR-018 invite list. @return BelongsToMany<User, $this> */
    public function invitedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'open_role_invites')->withPivot('invited_by')->withTimestamps();
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @param Builder<OpenRole> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', OpenRoleStatus::Published->value);
    }

    /**
     * Published roles the viewer may see (spec 2.2, SCR-018). Guests and every non-contributor see `public` only;
     * an active contributor also sees `members_only`, and `invite_only` roles they are on the list for.
     * Every list, search, sitemap and detail page goes through this scope so a role cannot leak.
     *
     * @param  Builder<OpenRole>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->published()->where(function (Builder $visible) use ($user): void {
            $visible->where('open_roles.visibility', OpenRoleVisibility::Public->value);

            if ($user !== null && $user->isActiveContributor()) {
                $visible->orWhere('open_roles.visibility', OpenRoleVisibility::MembersOnly->value)
                    ->orWhere(fn (Builder $invite) => $invite
                        ->where('open_roles.visibility', OpenRoleVisibility::InviteOnly->value)
                        ->whereHas('invitedUsers', fn (Builder $users) => $users->where('users.id', $user->getKey())));
            }
        });
    }

    /** Published and not past its deadline. A role past its deadline stays visible but takes no applications. */
    public function isOpenForApplications(): bool
    {
        return $this->status === OpenRoleStatus::Published
            && ($this->deadline === null || ! $this->deadline->endOfDay()->isPast());
    }

    public function isVisibleTo(?User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
