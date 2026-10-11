<x-mail::message>
# New contact form message

**From:** {{ $contact->name }} ({{ $contact->email }})

@if ($contact->subject)
**Subject:** {{ $contact->subject }}

@endif
@foreach (preg_split('/\R/', $contact->message) as $line)
> {{ $line }}
@endforeach

Reply to this email to answer the sender. The message is also in Admin under Website, Contact Messages.

<x-mail::button :url="\App\Filament\Admin\Resources\ContactMessages\ContactMessageResource::getUrl('view', ['record' => $contact])">
Open in Admin
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
