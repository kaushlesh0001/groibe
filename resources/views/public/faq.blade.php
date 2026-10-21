<x-layouts.public title="FAQ">
    @push('meta')
        <meta name="description" content="Answers to common questions about Groibe for contributors and clients.">
        <link rel="canonical" href="{{ route('faq') }}">
        <meta property="og:title" content="FAQ | Groibe">
        <meta property="og:description" content="Answers to common questions about Groibe for contributors and clients.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('faq') }}">
        @if (! empty($groups))
            <script type="application/ld+json">
                {!! \App\Support\Seo\JsonLd::json([
                    '@type' => 'FAQPage',
                    'mainEntity' => collect($groups)->flatten(1)->map(fn ($faq) => [
                        '@type' => 'Question',
                        'name' => $faq->question,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq->answer],
                    ])->values()->all(),
                ]) !!}
            </script>
        @endif
    @endpush

    <div class="flex flex-col gap-8">
        <h1 class="text-3xl font-semibold">Frequently asked questions</h1>

        @if (empty($groups))
            <p class="text-zinc-700">No questions have been published yet. If you need an answer, <a class="text-brand-blue underline" href="{{ route('contact') }}">contact us</a>.</p>
        @else
            <x-faq-tabs :groups="$groups" />
        @endif
    </div>
</x-layouts.public>
