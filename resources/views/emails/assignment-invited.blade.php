<x-mail::message>
# You are invited to a project

Hello {{ $name }},

Groibe would like you to join **{{ $projectTitle }}** as **{{ $roleTitle }}**. Please accept or decline the invite when you can.

<x-mail::button :url="$url">
View invite
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
