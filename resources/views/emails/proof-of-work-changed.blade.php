<x-mail::message>
# {{ $headline }}

Hello {{ $name }},

@if ($event === 'submitted')
A proof-of-work item is waiting for your approval. Publishing needs the client's showcase consent.
@elseif ($event === 'published')
Your proof of work is now published. It appears publicly only where you set it to public and the client's consent allows it.
@elseif ($event === 'returned')
Your proof of work needs a few edits before it can be published.
@elseif ($event === 'hidden')
Your proof of work is no longer shown. Please contact Groibe if you have questions.
@else
{{ $headline }}.
@endif

@if ($note)
> {{ $note }}
@endif

<x-mail::button :url="$url">
Open
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
