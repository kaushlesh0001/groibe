<?php

namespace App\Actions\Messages;

use App\Actions\Files\AttachPrivateFile;
use App\Exceptions\ActionBlocked;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Notifications\NewMessage;
use App\Services\Messaging\ThreadAudience;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Posts a message (spec 14). Only an admin may mark it internal. Files are validated before anything is written, stored
 * privately. The sender's read marker is left alone, so a reply never hides a message that arrived while they typed. The activity log records who posted where, never the text.
 */
class PostMessage
{
    public function __construct(
        private AttachPrivateFile $files,
        private ThreadAudience $audience,
        private NotificationDispatcher $dispatcher,
    ) {}

    /** @param  array<int, UploadedFile>  $uploads */
    public function __invoke(Thread $thread, User $actor, string $body, array $uploads = [], bool $internal = false): Message
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('reply', $thread);

        if ($internal) {
            $gate->authorize('postInternal', $thread);
        }

        $body = trim($body);
        $this->guard($actor, $body, $uploads);

        $message = DB::transaction(function () use ($thread, $actor, $body, $uploads, $internal): Message {
            $message = Message::query()->create([
                'thread_id' => $thread->getKey(),
                'user_id' => $actor->getKey(),
                'body' => $body,
                'is_internal' => $internal,
            ]);

            foreach ($uploads as $file) {
                $this->files->store($message, $file, $actor, Message::FILES, (int) config('groibe.uploads.document_max_kb'));
            }

            Thread::query()->whereKey($thread->getKey())->update(['last_message_at' => $message->created_at->format('Y-m-d H:i:s.u')]);

            activity('message')->causedBy($actor)->performedOn($thread)->event('posted')
                ->withProperties(['message_id' => $message->getKey(), 'internal' => $internal, 'files' => count($uploads)])
                ->log($internal ? 'Internal message posted' : 'Message posted');

            return $message;
        });

        $this->dispatcher->send($this->audience->recipientsFor($message), NewMessage::for($thread));

        return $message;
    }

    /**
     * @param  array<int, UploadedFile>  $uploads
     *
     * @throws ActionBlocked
     */
    private function guard(User $actor, string $body, array $uploads): void
    {
        if ($body === '') {
            throw ActionBlocked::guardFailed('Write a message first.');
        }

        if (mb_strlen($body) > (int) config('groibe.messages.max_chars')) {
            throw ActionBlocked::guardFailed('That message is too long. The limit is '.number_format((int) config('groibe.messages.max_chars')).' characters.');
        }

        if (count($uploads) > (int) config('groibe.messages.max_files')) {
            throw ActionBlocked::guardFailed('You can attach up to '.config('groibe.messages.max_files').' files to one message.');
        }

        $limit = 'message:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($limit, (int) config('groibe.messages.per_minute'))) {
            throw ActionBlocked::guardFailed('You are sending messages too quickly. Please wait a moment.');
        }

        // Validate every file before the message exists, so a bad file never leaves a half-posted message.
        foreach ($uploads as $file) {
            $this->files->validate($file, (int) config('groibe.uploads.document_max_kb'));
        }

        RateLimiter::hit($limit, 60);
    }
}
