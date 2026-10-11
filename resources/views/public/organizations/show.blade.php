<x-layouts.public :title="$organization->name">
    @push('meta')
        <meta name="description" content="{{ \Illuminate\Support\Str::limit((string) ($organization->description ?: $organization->name.' works with Groibe.'), 155) }}">
        <link rel="canonical" href="{{ route('organizations.show', $organization->slug) }}">
        <meta property="og:title" content="{{ $organization->name }} | Groibe">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('organizations.show', $organization->slug) }}">
        <script type="application/ld+json">
            {!! json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Organizations', 'item' => route('organizations.index')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $organization->name, 'item' => route('organizations.show', $organization->slug)],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}
        </script>
    @endpush

    <article class="mx-auto flex max-w-3xl flex-col gap-6">
        <a class="w-fit text-brand-blue underline" href="{{ route('organizations.index') }}">All organizations</a>

        <header class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">{{ $organization->name }}</h1>
            <p class="text-zinc-700">{{ collect([$organization->industry, $organization->city, $organization->state])->filter()->implode(', ') }}</p>
        </header>

        @if ($organization->description)
            <p class="whitespace-pre-line">{{ $organization->description }}</p>
        @endif

        @if ($organization->website && preg_match('#^https?://#i', $organization->website))
            <p><a class="text-brand-blue underline" href="{{ $organization->website }}" rel="nofollow noopener noreferrer" target="_blank">Visit their website</a></p>
        @endif
    </article>
</x-layouts.public>
