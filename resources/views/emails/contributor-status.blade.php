<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

{{ $body }}

<x-mail::button :url="$url">
{{ $buttonLabel }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
