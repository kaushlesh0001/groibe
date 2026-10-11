<x-layouts.public :title="$template->title">
    @push('meta')
        <meta name="description" content="{{ $template->title }} for Groibe.">
        <link rel="canonical" href="{{ route($route) }}">
        <meta property="og:title" content="{{ $template->title }} | Groibe">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route($route) }}">
    @endpush

    <article class="mx-auto flex max-w-3xl flex-col gap-6">
        <h1 class="text-3xl font-semibold">{{ $template->title }}</h1>
        <p class="text-sm text-zinc-600">Version {{ $template->version }}, in force from {{ $template->effective_from?->toFormattedDateString() }}</p>
        <div class="prose max-w-none">{!! $template->body_html !!}</div>
    </article>
</x-layouts.public>
