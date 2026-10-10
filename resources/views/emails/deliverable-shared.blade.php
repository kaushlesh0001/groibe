<x-mail::message>
# Ready for your review

Hello {{ $name }},

**{{ $milestoneTitle }}** on **{{ $projectTitle }}** has been checked by our team and is ready for you. Please review it and accept it, or tell us what needs to change.

<x-mail::button :url="$url">
Review deliverables
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
