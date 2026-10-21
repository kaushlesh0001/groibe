<?php

namespace App\Models;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Exceptions\IllegalTransition;
use App\Models\Concerns\HasUuid;
use Database\Factories\DataRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A person's request for a copy of their data, or for its erasure (spec 17.2, SCR-028). Only a super admin processes it. The
 * `note` is the super admin's own text (how a copy was delivered, why a request was refused) and is never written to the audit log.
 */
class DataRequest extends Model
{
    /** @use HasFactory<DataRequestFactory> */
    use HasFactory, HasUuid, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['user_id', 'type', 'status', 'note', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return [
            'type' => DataRequestType::class,
            'status' => DataRequestStatus::class,
            'handled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('privacy')
            ->logOnly(['user_id', 'type', 'status', 'handled_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** The person, even after their row has been anonymised and soft-deleted. @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** The person the request is about. A request always has one: its row is kept even when the person is erased. */
    public function person(): User
    {
        $loaded = $this->relationLoaded('user') ? $this->getRelation('user') : null;

        return $loaded instanceof User ? $loaded : User::withTrashed()->findOrFail($this->user_id);
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by')->withTrashed();
    }

    /** @param Builder<DataRequest> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', [DataRequestStatus::Open->value, DataRequestStatus::InProgress->value]);
    }

    /** Still waiting (open or in progress) and made more than this many days ago (SCR-039). @param Builder<DataRequest> $query */
    public function scopeWaitingLongerThan(Builder $query, int $days): void
    {
        $query->whereIn('status', [DataRequestStatus::Open->value, DataRequestStatus::InProgress->value])->where('created_at', '<', now()->subDays($days));
    }

    /** Moves to a new status, or throws when the state machine does not allow it. */
    public function moveTo(DataRequestStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw IllegalTransition::for('data request', $this->status->value, $to->value);
        }

        $this->status = $to;
        $this->save();
    }
}
