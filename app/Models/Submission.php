<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/** A contributor's delivered work for a task (spec 6.8, 7.3). A resubmission is a new row with version + 1. */
class Submission extends Model implements HasMedia
{
    public const FILES = 'submission_files';

    /** @use HasFactory<SubmissionFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'assignment_id',
        'user_id',
        'note',
        'status',
        'qa_note',
        'reviewed_by',
        'reviewed_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'version' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['task_id', 'assignment_id', 'user_id', 'status', 'version', 'reviewed_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** Private by default: the `local` disk is never web-served (spec 17.1). */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FILES)->useDisk('local');
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsToMany<Deliverable, $this> */
    public function deliverables(): BelongsToMany
    {
        return $this->belongsToMany(Deliverable::class, 'deliverable_submission')->withTimestamps();
    }
}
