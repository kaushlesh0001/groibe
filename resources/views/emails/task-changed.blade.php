<x-mail::message>
# A task was updated

Hello {{ $name }},

{{ $what }} Task: **{{ $taskTitle }}** on **{{ $projectTitle }}**.

<x-mail::button :url="$url">
View task
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
