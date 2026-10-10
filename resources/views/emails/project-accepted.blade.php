<x-mail::message>
# Project accepted

Hello {{ $name }},

@if ($forAdmin)
The client accepted every milestone of **{{ $projectTitle }}**. The project can now be closed.
@else
The client accepted the work on **{{ $projectTitle }}**. Your assignment is now complete. Thank you.
@endif

<x-mail::button :url="$url">
Open
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
