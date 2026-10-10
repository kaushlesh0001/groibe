<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

@if ($event === 'shortlisted')
The team liked your application for **{{ $roleTitle }}** and has shortlisted you. We will be in touch about next steps.
@elseif ($event === 'offered')
Groibe would like to offer you **{{ $roleTitle }}**. Please accept or decline the offer when you can.
@elseif ($event === 'rejected')
Thank you for applying for **{{ $roleTitle }}**. We are not able to take your application forward this time.
@else
{{ $headline }}.
@endif

<x-mail::button :url="$url">
View details
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
