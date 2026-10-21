<x-mail::message>
# Your sign-in code

Hello {{ $name }},

Use this code to finish signing in to the Groibe admin panel:

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

It works once and stops working after {{ $minutes }} minutes. A newer code replaces this one.

If you did not try to sign in, do not share this code. Change your password and tell a super admin.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
