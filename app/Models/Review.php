<?php

namespace App\Models;

use App\Enums\ReviewDirection;
use App\Enums\ReviewRevieweeType;
use App\Enums\ReviewStatus;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A moderated review between a project's parties (spec 7.5, 9). `reviewee_id` points at a user or an organization. */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'reviewer_id',
        'reviewee_type',
        'reviewee_id',
        'direction',
        'quality',
        'communication',
        'reliability',
        'timeliness',
        'clarity',
        'professionalism',
        'feedback_time',
        'fairness',
        'comment',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewee_type' => ReviewRevieweeType::class,
            'direction' => ReviewDirection::class,
            'status' => ReviewStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'reviewer_id', 'reviewee_type', 'reviewee_id', 'direction', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * The contributor being reviewed. Only meaningful when `reviewee_type` is `user`.
     *
     * @return BelongsTo<User, $this>
     */
    public function revieweeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewee_id');
    }

    /**
     * The client organization being reviewed. Only meaningful when `reviewee_type` is `organization`.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function revieweeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'reviewee_id');
    }

    /** Mean of the criteria that apply to this review's direction, or null when none were rated. */
    public function rating(): ?float
    {
        $values = array_filter(
            array_map(fn (string $column): ?int => $this->getAttribute($column), $this->direction->criteria()),
            fn (?int $value): bool => $value !== null,
        );

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** @param Builder<Review> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('reviews.status', ReviewStatus::Published->value);
    }

    /** @param Builder<Review> $query */
    public function scopeAboutUser(Builder $query, User|int $user): void
    {
        $query->where('reviews.reviewee_type', ReviewRevieweeType::User->value)
            ->where('reviews.reviewee_id', $user instanceof User ? $user->getKey() : $user);
    }

    /** @param Builder<Review> $query */
    public function scopeAboutOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('reviews.reviewee_type', ReviewRevieweeType::Organization->value)
            ->where('reviews.reviewee_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }
}
