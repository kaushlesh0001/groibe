<x-mail::message>
# Work submitted for QA

Hello {{ $name }},

{{ $contributorName }} submitted version {{ $version }} of **{{ $taskTitle }}** on **{{ $projectTitle }}**. It is waiting for internal review.

<x-mail::button :url="$url">
Open project
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
