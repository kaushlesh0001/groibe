<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

There is an update on one of your payouts. For privacy the amount is not in this email. Sign in to see the details.

<x-mail::button :url="$url">
View payouts
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
