<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\RateType;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A contributor's seat on a project (spec 6.6, 7.4). Rates and amounts are never shown to clients. */
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'user_id',
        'role_title',
        'status',
        'rate_type',
        'agreed_amount',
        'currency',
        'start_date',
        'end_date',
        'invited_at',
        'accepted_at',
        'completed_at',
        'removed_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'rate_type' => RateType::class,
            'agreed_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'user_id', 'role_title', 'status', 'rate_type', 'agreed_amount', 'removed_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** @param Builder<Assignment> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', AssignmentStatus::Active->value);
    }

    /** Invited or active: the seat is taken (spec 7.4). @param Builder<Assignment> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (AssignmentStatus $s) => $s->value, AssignmentStatus::openStates()));
    }

    /** @param Builder<Assignment> $query */
    public function scopeForUser(Builder $query, User|int $user): void
    {
        $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }
}
