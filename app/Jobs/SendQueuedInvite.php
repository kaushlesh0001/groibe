<?php

namespace App\Jobs;

use App\Actions\Onboarding\SendInvite;
use App\Exceptions\IllegalTransition;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sends one invite (spec 15.1) when its turn comes. Quiet when the person has already activated or been removed since it was queued. */
class SendQueuedInvite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $userId, public int $sentById) {}

    public function handle(SendInvite $sendInvite): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        try {
            $sendInvite($user, User::query()->find($this->sentById));
        } catch (IllegalTransition) {
            // Already activated, suspended or given a password since the invite was queued: nothing to send.
        }
    }
}
