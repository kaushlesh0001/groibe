<x-mail::message>
# Project closed

Hello {{ $name }},

@if ($forClient)
**{{ $projectTitle }}** is now closed. Please review the team and choose whether, and how, Groibe may show this project as an example of our work.
@else
**{{ $projectTitle }}** is now closed. Your proof-of-work draft is ready for you to finish, and you can leave a review of the client.
@endif

<x-mail::button :url="$url">
Open
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
