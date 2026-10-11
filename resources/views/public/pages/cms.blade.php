<x-layouts.public :title="$page->meta_title ?: $page->title">
    @push('meta')
        <meta name="description" content="{{ $page->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($body), 155) }}">
        <link rel="canonical" href="{{ url($page->slug) }}">
        <meta property="og:title" content="{{ $page->meta_title ?: $page->title }} | Groibe">
        <meta property="og:description" content="{{ $page->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($body), 155) }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ url($page->slug) }}">
    @endpush

    <article class="mx-auto flex max-w-3xl flex-col gap-6">
        <h1 class="text-3xl font-semibold">{{ $page->title }}</h1>
        <div class="prose max-w-none">{!! $body !!}</div>
    </article>
</x-layouts.public>
