<?php

namespace App\Actions\Messages;

use App\Enums\ThreadType;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** A support thread (spec 14): private to the person who starts it and the admins who answer support. */
class StartSupportThread
{
    public function __construct(private PostMessage $post) {}

    /** @param  array<int, UploadedFile>  $uploads */
    public function __invoke(User $actor, string $subject, string $body, array $uploads = []): Thread
    {
        Gate::forUser($actor)->authorize('startSupport', Thread::class);

        $data = Validator::make(['subject' => trim($subject)], ['subject' => ['required', 'string', 'min:3', 'max:120']])->validate();

        return DB::transaction(function () use ($actor, $data, $body, $uploads): Thread {
            $thread = Thread::query()->create([
                'type' => ThreadType::Support->value,
                'subject' => $data['subject'],
                'created_by' => $actor->getKey(),
            ]);

            ($this->post)($thread, $actor, $body, $uploads);

            return $thread;
        });
    }
}
