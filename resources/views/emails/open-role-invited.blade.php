<x-mail::message>
# You are invited to apply

Hello {{ $name }},

Groibe has invited you to apply for **{{ $roleTitle }}**. It is open to a small group of contributors, and you are one of them.

<x-mail::button :url="$url">
View the role
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
