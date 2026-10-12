<?php

namespace App\Models;

use App\Enums\FeatureFlag;
use App\Enums\ThreadType;
use App\Models\Concerns\HasUuid;
use App\Services\FeatureFlags\FeatureFlags;
use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation (spec 7.8, 14). Who may read it is never stored: `visibleTo` derives it from the live project and
 * assignment state, so a removed contributor or a suspended account loses access at once. `thread_participants` only
 * keeps each person's read marker.
 */
class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory, HasUuid;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'type',
        'subject',
        'created_by',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ThreadType::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'thread_participants')->withPivot('last_read_at')->withTimestamps();
    }

    /**
     * The only definition of "this person may see this thread". Policies, lists, unread counts and the admin screens all
     * go through it. Nothing is visible while the `messaging` flag is off.
     *
     * - Project threads: staff holding `threads.view`; active contributors with an active or completed seat; client owners
     *   and managers of the project's organization (see Project::scopeMessageableBy).
     * - Support threads: the person who started it, and admins holding `support.view`.
     *
     * @param  Builder<Thread>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (app(FeatureFlags::class)->disabled(FeatureFlag::Messaging)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $threads) use ($user): void {
            $threads->where(fn (Builder $own) => $own
                ->where('threads.type', ThreadType::Support->value)
                ->where('threads.created_by', $user->getKey()));

            if ($user->isAdmin() && $user->can('support.view')) {
                $threads->orWhere('threads.type', ThreadType::Support->value);
            }

            $threads->orWhere(fn (Builder $project) => $project
                ->where('threads.type', ThreadType::Project->value)
                ->whereIn('threads.project_id', Project::query()->messageableBy($user)->select('projects.id')));
        });
    }

    /**
     * Adds `unread_count`: messages from other people, visible to this person, newer than their read marker.
     *
     * @param  Builder<Thread>  $query
     */
    public function scopeWithUnread(Builder $query, User $user): void
    {
        $query->withCount(['messages as unread_count' => fn (Builder $messages) => $messages->scopes(['unreadBy' => [$user]])]);
    }

    /** @param Builder<Thread> $query */
    public function scopeHavingUnread(Builder $query, User $user): void
    {
        $query->whereHas('messages', fn (Builder $messages) => $messages->unreadBy($user));
    }
}
