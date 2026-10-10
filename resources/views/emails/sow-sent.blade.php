<x-mail::message>
# Statement of Work ready for your review

Hello {{ $name }},

Version {{ $version }} of the Statement of Work for **{{ $projectTitle }}** is ready. Please review it and accept it when you are happy with the scope.

<x-mail::button :url="$url">
Review Statement of Work
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
