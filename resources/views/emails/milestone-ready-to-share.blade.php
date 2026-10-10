<x-mail::message>
# Milestone in QA

Hello {{ $name }},

Every task on **{{ $milestoneTitle }}** ({{ $projectTitle }}) is submitted or approved. Review what is left, build the deliverable and share it with the client.

<x-mail::button :url="$url">
Open project
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
