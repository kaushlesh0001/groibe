<x-mail::message>
# Statement of Work accepted

Hello {{ $name }},

Version {{ $version }} of the Statement of Work for **{{ $projectTitle }}** was accepted by {{ $acceptedBy }}.

<x-mail::button :url="$url">
Open project
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
