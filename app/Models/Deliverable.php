<?php

namespace App\Models;

use Database\Factories\DeliverableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A client-facing output bundle on a milestone, built from qa_approved submissions (spec 3, 7.3). */
class Deliverable extends Model
{
    /** @use HasFactory<DeliverableFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'milestone_id',
        'title',
        'summary',
        'shared_at',
    ];

    protected function casts(): array
    {
        return [
            'shared_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['milestone_id', 'title', 'shared_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Milestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** @return BelongsToMany<Submission, $this> */
    public function submissions(): BelongsToMany
    {
        return $this->belongsToMany(Submission::class, 'deliverable_submission')->withTimestamps();
    }
}
