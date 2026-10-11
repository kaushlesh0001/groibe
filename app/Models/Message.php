<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/** One message in a thread (spec 7.8). `is_internal` messages are visible to admins only, whoever else is in the thread. */
class Message extends Model implements HasMedia
{
    public const FILES = 'message_files';

    /** @use HasFactory<MessageFactory> */
    use HasFactory, InteractsWithMedia;

    /** Microseconds, so "newer than my read marker" is exact even for messages sent in the same second. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'thread_id',
        'user_id',
        'body',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    /** Private by default: the `local` disk is never web-served (spec 17.1). */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FILES)->useDisk('local');
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Internal messages are for admins only (spec 14). Every read of messages for a person goes through here.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->where('messages.is_internal', false);
        }
    }

    /**
     * Messages from other people, visible to this person, newer than their read marker on the thread.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeUnreadBy(Builder $query, User $user): void
    {
        $query->visibleTo($user)
            ->where('messages.user_id', '!=', $user->getKey())
            ->whereNotExists(fn ($marker) => $marker
                ->selectRaw('1')
                ->from('thread_participants')
                ->whereColumn('thread_participants.thread_id', 'messages.thread_id')
                ->where('thread_participants.user_id', $user->getKey())
                ->whereColumn('thread_participants.last_read_at', '>=', 'messages.created_at'));
    }
}
