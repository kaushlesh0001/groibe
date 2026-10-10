<x-mail::message>
# Overdue tasks

Hello {{ $name }},

**{{ $projectTitle }}** has {{ $total }} open {{ $total === 1 ? 'task' : 'tasks' }} past the due date.

@foreach ($tasks as $task)
- **{{ $task['title'] }}**: due {{ $task['due'] }}, {{ $task['status'] }}, {{ $task['assignee'] }}
@endforeach
@if ($total > count($tasks))

and {{ $total - count($tasks) }} more.
@endif

<x-mail::button :url="$url">
Open workspace
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
