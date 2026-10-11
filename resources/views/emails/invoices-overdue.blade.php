<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

These invoice references passed their due date and are now marked overdue:

@foreach ($invoices as $invoice)
- {{ $invoice['project'] }}, invoice {{ $invoice['ref'] }}, due {{ $invoice['due'] }}
@endforeach

<x-mail::button :url="$url">
Open invoice references
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
