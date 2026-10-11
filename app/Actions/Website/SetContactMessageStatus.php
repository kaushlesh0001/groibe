<?php

namespace App\Actions\Website;

use App\Enums\ContactMessageStatus;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Moves a contact message between new, read and handled (SCR-021). Any move is allowed. Handling records who and when;
 * reopening clears both. The status change is written to the activity log by the model, without the message text.
 */
class SetContactMessageStatus
{
    public function __invoke(ContactMessage $message, ContactMessageStatus $to, User $actor): ContactMessage
    {
        Gate::forUser($actor)->authorize('update', $message);

        if ($message->status === $to) {
            return $message;
        }

        $message->forceFill([
            'status' => $to,
            'handled_by' => $to === ContactMessageStatus::Handled ? $actor->getKey() : null,
            'handled_at' => $to === ContactMessageStatus::Handled ? now() : null,
        ])->save();

        return $message;
    }
}
