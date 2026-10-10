<?php

namespace App\Models;

use App\Enums\AdminRole;
use App\Enums\OrgRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
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

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
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
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
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
        return $this->hasOne(Profile::class);
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

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)
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

    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && $this->hasRole(AdminRole::SuperAdmin->value);
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
