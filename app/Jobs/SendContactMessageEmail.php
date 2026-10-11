<?php

namespace App\Jobs;

use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a stored contact message to the address in `groibe.contact_email` (spec 10.2, 14). Runs on the queue, retries
 * a few times, and records when it was sent. A message that no longer exists, or one already sent, is skipped.
 */
class SendContactMessageEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> seconds between tries */
    public array $backoff = [60, 300];

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $message = ContactMessage::query()->find($this->messageId);
        $to = config('groibe.contact_email');

        if ($message === null || $message->email_sent_at !== null || blank($to)) {
            return;
        }

        Mail::to($to)->send(new ContactMessageReceived($message));

        $message->forceFill(['email_sent_at' => now()])->saveQuietly();
    }
}
