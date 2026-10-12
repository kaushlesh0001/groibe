<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

@if ($event === 'received')
A person has asked for {{ $type === 'export' ? 'a copy of their data' : 'their data to be erased' }}. Only a super admin can process it.
@elseif ($event === 'completed')
Groibe has finished your request. The team will send you the copy by email, or you will find the details on the page below.
@elseif ($event === 'rejected')
Groibe could not carry out your request. The reason is on the page below. You can write to us from the support page if you would like to talk about it.
@else
{{ $headline }}.
@endif

<x-mail::button :url="$url">
View details
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
