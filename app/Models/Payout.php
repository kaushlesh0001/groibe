<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A reference to money owed or paid to a contributor (spec 7.6). Nothing here moves money. Clients never see payouts.
 * `reference` holds a transaction reference (UTR) only, never account or card numbers (spec 17.2).
 */
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'assignment_id',
        'user_id',
        'project_id',
        'milestone_id',
        'amount',
        'currency',
        'status',
        'reference',
        'tds_amount',
        'approved_at',
        'paid_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount' => 'decimal:2',
            'tds_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** Notes and the reference are not written to the audit trail as values: who did what, to which payout, is enough. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['assignment_id', 'project_id', 'milestone_id', 'amount', 'status', 'tds_amount', 'paid_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** Amount after TDS, worked out here so it can never disagree with the two stored figures. */
    public function net(): string
    {
        return bcsub((string) $this->amount, (string) ($this->tds_amount ?? '0'), 2);
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Milestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What a contributor may see of their own money (SCR-024): payouts finance has approved, which includes ones later
     * paid or cancelled. A payout still `planned`, or cancelled before approval, never appears.
     *
     * @param  Builder<Payout>  $query
     */
    public function scopeVisibleToContributor(Builder $query, User $user): void
    {
        $query->where('payouts.user_id', $user->getKey())->whereNotNull('payouts.approved_at');
    }
}
