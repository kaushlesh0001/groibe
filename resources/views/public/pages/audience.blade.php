<x-layouts.public :title="$page['title']">
    @push('meta')
        <meta name="description" content="{{ $page['description'] }}">
        <link rel="canonical" href="{{ route($slug) }}">
        <meta property="og:title" content="{{ $page['title'] }} | Groibe">
        <meta property="og:description" content="{{ $page['description'] }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route($slug) }}">
    @endpush

    <article class="mx-auto flex max-w-3xl flex-col gap-10">
        <header class="flex flex-col gap-3">
            <h1 class="text-3xl font-semibold">{{ $page['title'] }}</h1>
            <p class="text-lg text-zinc-700">{{ $page['intro'] }}</p>
        </header>

        @foreach ($page['blocks'] as $block)
            <section class="flex flex-col gap-3" aria-labelledby="block-{{ $loop->iteration }}">
                <h2 id="block-{{ $loop->iteration }}" class="text-xl font-semibold">{{ $block['heading'] }}</h2>
                <ul class="flex list-disc flex-col gap-2 pl-6 text-zinc-800">
                    @foreach ($block['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <p>
            <a href="{{ route($page['cta']['route']) }}" class="inline-block rounded-lg bg-brand-green px-6 py-3 font-semibold text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-navy">{{ $page['cta']['label'] }}</a>
        </p>
    </article>
</x-layouts.public>
