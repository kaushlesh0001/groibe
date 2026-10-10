<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

{{ $body }}

<x-mail::button :url="$url">
Open
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
