<x-mail::message>
# Your project has been delivered

Hello {{ $name }},

All milestones of **{{ $projectTitle }}** have been shared with you. Please review each one and accept it, or tell us what needs to change.

<x-mail::button :url="$url">
Open project
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
