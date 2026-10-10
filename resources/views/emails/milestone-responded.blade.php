<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

{{ $headline }} on **{{ $projectTitle }}**.

@if ($note)
**Client note:** {{ $note }}
@endif

<x-mail::button :url="$url">
Open project
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
