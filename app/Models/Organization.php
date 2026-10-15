<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuid, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'gstin',
        'website',
        'industry',
        'size_band',
        'description',
        'logo_path',
        'city',
        'state',
        'country',
        'status',
        'verified_at',
        'can_submit_briefs',
        'is_public',
        'is_featured',
        'zoho_crm_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'verified_at' => 'datetime',
            'can_submit_briefs' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * `verified_at` follows the status (spec 7.1): it is stamped when an organization becomes verified and cleared when it stops
     * being verified (suspended, or sent back to pending). Doing it here covers every way a status changes. Who verified is
     * the causer of the logged status change, so no column for it is needed.
     */
    protected static function booted(): void
    {
        static::saving(function (Organization $organization): void {
            if (! $organization->isDirty('status')) {
                return;
            }

            if ($organization->status === OrganizationStatus::Verified) {
                $organization->verified_at ??= now();
            } else {
                $organization->verified_at = null;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'status', 'verified_at', 'can_submit_briefs', 'is_public', 'is_featured', 'gstin'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsToMany<User, $this, OrganizationUser, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->using(OrganizationUser::class)
            ->withPivot(['org_role', 'designation'])
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Organization> $query */
    public function scopeVerified(Builder $query): void
    {
        $query->where('status', OrganizationStatus::Verified);
    }

    /** @param Builder<Organization> $query */
    public function scopePublic(Builder $query): void
    {
        $query->where('is_public', true)->where('status', OrganizationStatus::Verified);
    }

    public function isVerified(): bool
    {
        return $this->status === OrganizationStatus::Verified;
    }

    /** Pending, and not cleared by an admin to submit briefs anyway (spec 6.11): the client is told to wait. */
    public function isAwaitingVerification(): bool
    {
        return $this->status === OrganizationStatus::Pending && ! $this->can_submit_briefs;
    }
}
