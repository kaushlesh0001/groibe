<?php

namespace App\Models;

use Database\Factories\ReputationFactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cached evidence counts and averages for one contributor (spec 9). Evidence only, never a composite score. */
class ReputationFact extends Model
{
    /** @use HasFactory<ReputationFactFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'projects_completed',
        'tasks_approved_first_pass',
        'tasks_total',
        'tasks_with_due_date',
        'on_time_tasks',
        'reviews_count',
        'avg_rating',
        'verified_skills_count',
        'recomputed_at',
    ];

    protected function casts(): array
    {
        return [
            'avg_rating' => 'decimal:2',
            'recomputed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Approved tasks accepted by QA on the first submission, as a whole percent. Null until a task has been approved. */
    public function firstPassPercent(): ?int
    {
        return $this->tasks_total === 0 ? null : (int) round($this->tasks_approved_first_pass / $this->tasks_total * 100);
    }

    /** Approved tasks with a due date that were approved on or before it, as a whole percent. Null when no such task exists. */
    public function onTimePercent(): ?int
    {
        return $this->tasks_with_due_date === 0 ? null : (int) round($this->on_time_tasks / $this->tasks_with_due_date * 100);
    }
}
