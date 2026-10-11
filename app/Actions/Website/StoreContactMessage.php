<?php

namespace App\Actions\Website;

use App\Jobs\SendContactMessageEmail;
use App\Models\ContactMessage;

/**
 * Stores a message from the public contact form, then queues the email to the Groibe team (spec 10.2). The message is
 * saved first, so it is never lost if the email fails. Only the visitor's own words, address and the request details
 * kept for abuse handling are stored.
 */
class StoreContactMessage
{
    /** @param array{name: string, email: string, subject?: ?string, message: string} $data */
    public function __invoke(array $data, ?string $ip, ?string $userAgent): ContactMessage
    {
        $message = ContactMessage::query()->create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'subject' => filled($data['subject'] ?? null) ? trim((string) $data['subject']) : null,
            'message' => trim($data['message']),
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);

        if (filled(config('groibe.contact_email'))) {
            SendContactMessageEmail::dispatch($message->getKey())->afterCommit();
        }

        return $message;
    }
}
