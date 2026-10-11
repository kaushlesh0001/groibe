@php
    $byKey = collect($sections)->keyBy('key');
    $hero = $byKey->get('hero');
    $body = collect($sections)->reject(fn ($section) => $section['key'] === 'hero');
@endphp

<x-layouts.public title="Where Ideas Find People">
    @push('meta')
        <meta name="description" content="{{ $hero['content']['subheading'] ?? 'Groibe assembles and manages teams of skilled professionals to deliver outcomes for growing businesses.' }}">
        <link rel="canonical" href="{{ route('home') }}">
        <meta property="og:title" content="Where Ideas Find People | {{ config('app.name') }}">
        <meta property="og:description" content="{{ $hero['content']['subheading'] ?? 'Groibe assembles and manages teams of skilled professionals to deliver outcomes for growing businesses.' }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('home') }}">
        <script type="application/ld+json">
            {!! json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => config('app.name'),
                'url' => route('home'),
                'logo' => asset('brand/groibe-logo.png'),
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}
        </script>
    @endpush

    @if ($hero)
        @php($c = $hero['content'])
        <x-slot:hero>
            <div class="flex flex-col gap-6">
                {{-- One highlighted word per headline. Green on navy is 4.65:1; green is never used as text on white (spec 10.3). --}}
                <h1 class="max-w-3xl text-4xl font-bold tracking-tight sm:text-6xl">{{ $c['heading'] }} <span class="text-brand-green">{{ $c['highlight'] }}</span></h1>
                <p class="max-w-2xl text-lg text-zinc-200">{{ $c['subheading'] }}</p>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                    @if (! empty($c['primary_url']) || $hero['data']['explore'])
                        <a href="{{ $c['primary_url'] ?? route('opportunities.index') }}" class="rounded-lg bg-brand-green px-6 py-3 font-semibold text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">{{ $c['primary_label'] }}</a>
                    @endif
                    <a href="{{ $c['secondary_url'] ?? route('register.contributor') }}" class="rounded-lg border border-white px-6 py-3 font-semibold text-white hover:bg-white hover:text-brand-navy focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">{{ $c['secondary_label'] }}</a>
                </div>
                <p class="text-zinc-200">
                    {{ $c['client_line'] }}
                    <a href="{{ $c['client_link_url'] ?? route('register.client') }}" class="rounded-md font-medium text-white underline underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">{{ $c['client_link_label'] }}</a>
                </p>
            </div>
        </x-slot:hero>
    @endif

    <div class="flex flex-col gap-20">
        @foreach ($body as $section)
            @include('public.home.'.str_replace('_', '-', $section['key']), ['c' => $section['content'], 'd' => $section['data']])
        @endforeach
    </div>
</x-layouts.public>
