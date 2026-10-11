<x-mail::message>
# A review is waiting for moderation

Hello {{ $name }},

A new review was submitted. Please publish or hide it from the moderation queue.

<x-mail::button :url="$url">
Open the queue
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
