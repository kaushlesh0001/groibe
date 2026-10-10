<x-mail::message>
# {{ $approved ? 'Your work was approved' : 'Changes requested' }}

Hello {{ $name }},

@if ($approved)
Version {{ $version }} of **{{ $taskTitle }}** on **{{ $projectTitle }}** was approved.
@else
Version {{ $version }} of **{{ $taskTitle }}** on **{{ $projectTitle }}** needs changes before it can be approved.
@endif

@if ($note)
**Reviewer note:** {{ $note }}
@endif

<x-mail::button :url="$url">
Open task
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
