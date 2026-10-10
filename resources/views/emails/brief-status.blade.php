<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

@if ($note)
{{ $note }}

@endif
<x-mail::button :url="$url">
View brief
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
