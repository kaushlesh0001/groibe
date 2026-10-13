<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

The database is using {{ $megabytes }} MB of the {{ $limit }} MB the host plan allows. When it reaches the limit the host can stop accepting writes, which would stop sign-ins, messages and uploads.

Old imports and the email log are already cleaned up automatically. Free more space, or ask the host for a larger plan. You will get this notice once a day while the database stays above the warning level.

<x-mail::button :url="$url">
Open Admin
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
