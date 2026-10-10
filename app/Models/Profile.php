<?php

namespace App\Models;

use App\Enums\Availability;
use App\Enums\OnboardingState;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'headline',
        'bio',
        'phone',
        'city',
        'state',
        'country',
        'photo_path',
        'linkedin_url',
        'portfolio_url',
        'availability',
        'hours_per_week',
        'work_modes',
        'experience_years',
        'languages',
        'is_public',
        'slug',
        'onboarding_state',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'availability' => Availability::class,
            'onboarding_state' => OnboardingState::class,
            'work_modes' => 'array',
            'languages' => 'array',
            'is_public' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Profiles the public may see: public, approved, and owned by an active contributor (spec 5, 10.4).
     * Every public route, search and the sitemap go through this one scope so a private profile cannot leak.
     *
     * @param  Builder<Profile>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('is_public', true)
            ->whereNotNull('slug')
            ->where('onboarding_state', OnboardingState::Active->value)
            ->whereHas('user', fn (Builder $user) => $user
                ->where('role', UserRole::Contributor->value)
                ->where('status', UserStatus::Active->value));
    }

    public function isPubliclyVisible(): bool
    {
        $this->loadMissing('user');

        return $this->is_public
            && $this->slug !== null
            && $this->onboarding_state === OnboardingState::Active
            && $this->user?->role === UserRole::Contributor
            && $this->user->status === UserStatus::Active;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
