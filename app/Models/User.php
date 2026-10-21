<?php

namespace App\Models;

use App\Enums\AdminRole;
use App\Enums\FeatureFlag as FeatureFlagKey;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Concerns\HasUuid;
use App\Services\FeatureFlags\FeatureFlags;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuid, LogsActivity, Notifiable, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'email_verified_at',
        'last_login_at',
        'timezone',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'role', 'status', 'timezone'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->role === UserRole::Admin
            && $this->status === UserStatus::Active;
    }

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class)->chaperone();
    }

    /** @return BelongsToMany<Organization, $this, OrganizationUser, 'pivot'> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->using(OrganizationUser::class)
            ->withPivot(['org_role', 'designation'])
            ->withTimestamps();
    }

    /** @var array<int, OrgRole|null> */
    private array $orgRoleCache = [];

    /** This user's role in the organization, or null when not a member. Memoized per request. */
    public function organizationRole(Organization $organization): ?OrgRole
    {
        if ($this->role !== UserRole::Client) {
            return null;
        }

        return $this->orgRoleCache[$organization->getKey()] ??= OrganizationUser::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $this->getKey())
            ->first()?->org_role;
    }

    /** Clients belong to one organization (spec 4). Returns it, or null for non-clients and unlinked clients. */
    public function primaryOrganization(): ?Organization
    {
        if ($this->role !== UserRole::Client) {
            return null;
        }

        return $this->organizations()->orderBy('organizations.id')->first();
    }

    /** @return BelongsToMany<Skill, $this, SkillUser, 'pivot'> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)
            ->using(SkillUser::class)
            ->withPivot(['level', 'years', 'is_verified', 'verified_by', 'verified_at'])
            ->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isContributor(): bool
    {
        return $this->role === UserRole::Contributor;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    /** Approved and not suspended: the only contributors who may be assigned or apply (spec 4). */
    public function isActiveContributor(): bool
    {
        return $this->isContributor()
            && $this->status === UserStatus::Active
            && $this->profile?->onboarding_state === OnboardingState::Active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && $this->hasRole(AdminRole::SuperAdmin->value);
    }

    /** Whether an authenticator app is set up. The emailed code is `mustUseEmailCode()`. */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null;
    }

    /**
     * Whether this admin signs in with an emailed code (spec 17.2, SCR-040): always for a super admin, and for any other admin role
     * while the `two_factor_admin` flag is on. It is decided here, from the role and the flag, so there is nothing to set up and
     * nothing to switch off. Nobody who is not an admin has it.
     */
    public function mustUseEmailCode(): bool
    {
        return $this->isAdmin() && ($this->isSuperAdmin() || app(FeatureFlags::class)->enabled(FeatureFlagKey::TwoFactorAdmin));
    }

    public function hasEmailAuthentication(): bool
    {
        return $this->mustUseEmailCode();
    }

    /** Filament's opt-in switch. The emailed code is required or not by role and flag, never by choice, so this does nothing. */
    public function toggleEmailAuthentication(bool $condition): void {}

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    /**
     * Saves or clears the authenticator secret (SCR-029). Turning it off also removes the recovery codes, so a stale set can
     * never be used to skip a later setup. The change goes to the audit log; the secret and the codes never do.
     */
    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => $secret === null ? null : now(),
            ...($secret === null ? ['two_factor_recovery_codes' => null] : []),
        ])->saveQuietly();

        activity('auth')->causedBy($this)->performedOn($this)
            ->event($secret === null ? 'two_factor_disabled' : 'two_factor_enabled')
            ->log($secret === null ? 'Two-factor authentication turned off' : 'Two-factor authentication turned on');
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<int, string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    /** @param  array<int, string>|null  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[\SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['two_factor_recovery_codes' => $codes])->saveQuietly();
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
