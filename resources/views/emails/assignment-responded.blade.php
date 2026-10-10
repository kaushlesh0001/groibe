<x-mail::message>
# Invite answered

Hello {{ $name }},

{{ $headline }}. Role: {{ $roleTitle }}.

<x-mail::button :url="$url">
Open project
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
