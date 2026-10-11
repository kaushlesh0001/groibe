<?php

namespace App\Models;

use App\Enums\ClientDisplay;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Enums\ShowcaseConsent;
use App\Models\Concerns\HasUuid;
use Database\Factories\ProofOfWorkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A contributor's record of a completed contribution (spec 7.5, 9). Public only while published, public and consented. */
class ProofOfWork extends Model
{
    /** @use HasFactory<ProofOfWorkFactory> */
    use HasFactory, HasUuid, LogsActivity;

    protected $table = 'proof_of_work';

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'user_id',
        'project_id',
        'title',
        'role_title',
        'summary',
        'contribution_text',
        'outcome_metrics',
        'visibility',
        'status',
        'client_display',
        'approved_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome_metrics' => 'array',
            'visibility' => ProofVisibility::class,
            'status' => ProofStatus::class,
            'client_display' => ClientDisplay::class,
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'role_title', 'summary', 'contribution_text', 'visibility', 'status', 'client_display', 'approved_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'proof_of_work_skill')->withTimestamps();
    }

    /** @param Builder<ProofOfWork> $query */
    public function scopeOwnedBy(Builder $query, User|int $user): void
    {
        $query->where('proof_of_work.user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * The one rule for anything the public can see (spec 9, Pass 6 gate): published, set to public, and the
     * project's showcase consent is anonymous or named right now. A consent later set back to `none` removes the
     * item from every public route, search and the sitemap at once, because the check runs on every read.
     *
     * @param  Builder<ProofOfWork>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('proof_of_work.status', ProofStatus::Published->value)
            ->where('proof_of_work.visibility', ProofVisibility::Public->value)
            ->whereNotNull('proof_of_work.slug')
            ->whereHas('project', fn (Builder $project) => $project->whereIn('showcase_consent', [
                ShowcaseConsent::Anonymous->value,
                ShowcaseConsent::Named->value,
            ]));
    }

    /** Same test as the scope, for one loaded record. */
    public function isPubliclyVisible(): bool
    {
        $this->loadMissing('project');

        return $this->status === ProofStatus::Published
            && $this->visibility === ProofVisibility::Public
            && $this->slug !== null
            && $this->project !== null
            && $this->project->showcase_consent !== ShowcaseConsent::None;
    }

    /** What the public may read about the client: the name only when the client consented to be named (spec 9). */
    public function clientLabel(): string
    {
        $this->loadMissing('project.organization');
        $project = $this->project;
        $organization = $project !== null ? $project->organization : null;

        if ($project !== null && $organization !== null && $project->showcase_consent === ShowcaseConsent::Named) {
            return $organization->name;
        }

        return 'Confidential client'.($organization?->industry ? ', '.$organization->industry : '');
    }
}
