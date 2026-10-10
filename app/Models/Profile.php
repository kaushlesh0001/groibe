<?php

namespace App\Models;

use App\Enums\Availability;
use App\Enums\OnboardingState;
use Database\Factories\ProfileFactory;
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
