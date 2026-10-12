<?php

namespace App\Models;

use App\Enums\MilestoneStatus;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A deliverable-level checkpoint on a project (spec 6.9, 7.3). */
class Milestone extends Model
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'title',
        'description',
        'sort',
        'due_date',
        'value',
        'status',
        'shared_at',
        'client_accepted_at',
        'client_accepted_by',
        'client_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            'sort' => 'integer',
            'due_date' => 'date',
            'value' => 'decimal:2',
            'shared_at' => 'datetime',
            'client_accepted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'title', 'sort', 'due_date', 'value', 'status', 'client_accepted_by', 'client_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function clientAccepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_accepted_by')->withTrashed();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Deliverable, $this> */
    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    /** @param Builder<Milestone> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /** Shared and later: what a client may see (spec 6.9). @param Builder<Milestone> $query */
    public function scopeClientVisible(Builder $query): void
    {
        $query->whereIn('status', [
            MilestoneStatus::Shared->value,
            MilestoneStatus::ClientAccepted->value,
            MilestoneStatus::ClientChangesRequested->value,
        ]);
    }
}
