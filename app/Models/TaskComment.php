<?php

namespace App\Models;

use Database\Factories\TaskCommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A comment on a task. `is_internal` hides it from clients (spec 7.3). */
class TaskComment extends Model
{
    /** @use HasFactory<TaskCommentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'user_id',
        'body',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<TaskComment> $query */
    public function scopeVisibleToClient(Builder $query): void
    {
        $query->where('is_internal', false);
    }
}
