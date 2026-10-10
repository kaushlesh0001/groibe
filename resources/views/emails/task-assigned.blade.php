<x-mail::message>
# A task was assigned to you

Hello {{ $name }},

**{{ $taskTitle }}** on **{{ $projectTitle }}** is now yours.@if ($due) It is due {{ $due }}.@endif

<x-mail::button :url="$url">
View task
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
