<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A contributor's application to an open role (spec 6.5, 7.4). Never edited once submitted (spec 22). */
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'open_role_id',
        'user_id',
        'status',
        'motivation',
        'relevant_experience',
        'availability_note',
        'expected_amount',
        'proof_of_work_ids',
        'admin_note',
        'shortlisted_at',
        'decided_at',
        'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'expected_amount' => 'decimal:2',
            'proof_of_work_ids' => 'array',
            'shortlisted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['open_role_id', 'user_id', 'status', 'admin_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<OpenRole, $this> */
    public function openRole(): BelongsTo
    {
        return $this->belongsTo(OpenRole::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param Builder<Application> $query */
    public function scopeForUser(Builder $query, User|int $user): void
    {
        $query->where('applications.user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /** @param Builder<Application> $query */
    public function scopeInPlay(Builder $query): void
    {
        $query->whereIn('applications.status', array_map(
            fn (ApplicationStatus $s) => $s->value,
            array_filter(ApplicationStatus::cases(), fn (ApplicationStatus $s) => $s->isOpen()),
        ));
    }
}
