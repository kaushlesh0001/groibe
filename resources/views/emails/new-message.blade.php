<x-mail::message>
# You have a new message

Hello {{ $name }},

There is a new message in **{{ $subject }}**. For privacy the message itself is not in this email. Sign in to read and reply.

<x-mail::button :url="$url">
Open the conversation
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
