<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

The oldest request has waited {{ $oldestDays }} days. A person is waiting for an answer about their own data.

Open Admin, then System, then Data requests. Take each waiting request and follow the steps in the operations runbook (`docs/OPERATIONS.md`). You will get this notice once a day while a request is still waiting.

<x-mail::button :url="$url">
Open Data requests
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
