@php
    $by = $item->user?->profile?->isPubliclyVisible() ? $item->user : null;
@endphp

<x-layouts.public :title="$item->title">
    @push('meta')
        <meta name="description" content="{{ \Illuminate\Support\Str::limit((string) ($item->summary ?: $item->contribution_text), 155) }}">
        <link rel="canonical" href="{{ route('work.show', $item->slug) }}">
        <meta property="og:title" content="{{ $item->title }} | Groibe">
        <meta property="og:description" content="{{ \Illuminate\Support\Str::limit((string) $item->summary, 155) }}">
        <meta property="og:type" content="article">
        <meta property="og:url" content="{{ route('work.show', $item->slug) }}">
        <script type="application/ld+json">
            {!! \App\Support\Seo\JsonLd::json([
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Our work', 'item' => route('work.index')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $item->title, 'item' => route('work.show', $item->slug)],
                ],
            ]) !!}
        </script>
    @endpush

    <article class="mx-auto flex max-w-3xl flex-col gap-6">
        <a class="w-fit text-brand-blue underline" href="{{ route('work.index') }}">All work</a>

        <header class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">{{ $item->title }}</h1>
            <p class="text-zinc-600">{{ $item->clientLabel() }}</p>
            <p class="text-zinc-600">
                {{ $item->role_title }}
                @if ($by)
                    · <a class="text-brand-blue underline" href="{{ route('contributors.show', $by->profile->slug) }}">{{ $by->name }}</a>
                @endif
            </p>
        </header>

        @if ($item->summary)
            <p class="text-lg">{{ $item->summary }}</p>
        @endif

        @if ($item->contribution_text)
            <section aria-labelledby="contribution-heading" class="flex flex-col gap-2">
                <h2 id="contribution-heading" class="text-xl font-semibold">What was done</h2>
                <p class="whitespace-pre-line">{{ $item->contribution_text }}</p>
            </section>
        @endif

        @if (! empty($item->outcome_metrics))
            <section aria-labelledby="outcomes-heading" class="flex flex-col gap-2">
                <h2 id="outcomes-heading" class="text-xl font-semibold">Outcomes</h2>
                <dl class="grid gap-3 sm:grid-cols-2">
                    @foreach ($item->outcome_metrics as $metric)
                        <div class="rounded-lg border border-zinc-200 p-3"><dt class="text-sm text-zinc-600">{{ $metric['label'] }}</dt><dd class="text-lg font-semibold">{{ $metric['value'] }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if ($item->skills->isNotEmpty())
            <section aria-labelledby="skills-heading" class="flex flex-col gap-2">
                <h2 id="skills-heading" class="text-xl font-semibold">Skills shown</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($item->skills as $skill)
                        <li class="rounded border border-zinc-200 px-3 py-1 text-sm">{{ $skill->name }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>
</x-layouts.public>
