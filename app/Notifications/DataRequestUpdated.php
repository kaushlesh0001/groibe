<?php

namespace App\Notifications;

use App\Enums\DataRequestType;
use App\Filament\Admin\Resources\DataRequests\DataRequestResource;
use App\Models\DataRequest;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A data request (spec 17.2, SCR-028). Super admins hear that one has been opened. The person hears that their copy is ready
 * or that the request was not carried out. A completed erasure sends nothing, because the account no longer exists. The
 * message names the kind of request and the outcome only, and carries no personal data.
 */
class DataRequestUpdated extends GroibeNotification
{
    public function __construct(public DataRequest $request, public string $event, public bool $toAdmin = false) {}

    /** Always sent on both channels: a person must hear what happened to a request about their own data. */
    public function category(): string
    {
        return 'security';
    }

    public function headline(): string
    {
        $what = $this->request->type === DataRequestType::Export ? 'copy of your data' : 'erasure of your data';

        return match ($this->event) {
            'received' => $this->request->type === DataRequestType::Export ? 'A data export request is waiting' : 'A data erasure request is waiting',
            'completed' => "Your request for a {$what} was completed",
            'rejected' => "Your request for {$what} was not carried out",
            default => 'Update on your data request',
        };
    }

    public function url(): string
    {
        return $this->toAdmin ? DataRequestResource::getUrl('index') : route('settings.privacy');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => null,
            'url' => $this->url(),
            'data_request_id' => $this->request->getKey(),
            'event' => $this->event,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.data-request-updated', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'event' => $this->event,
                'type' => $this->request->type->value,
                'url' => $this->url(),
            ]);
    }
}
