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
            {!! \App\Support\Seo\JsonLd::json([
                '@type' => 'Organization',
                'name' => config('app.name'),
                'url' => route('home'),
                'logo' => asset('brand/groibe-logo.png'),
            ]) !!}
        </script>
    @endpush

    @if ($hero)
        @php($c = $hero['content'])
        <x-slot:hero>
            <div class="flex flex-col gap-6">
                {{-- One highlighted word per headline. Green on navy is 4.65:1; green is never used as text on white (spec 10.3). --}}
                {{-- The slogan is plain text in the H1. Each word is a span only so CSS can fade the words in one after another (opacity only, so nothing moves). --}}
                <h1 class="max-w-3xl text-4xl font-bold tracking-tight sm:text-6xl">@foreach (preg_split('/\s+/', trim($c['heading']), -1, PREG_SPLIT_NO_EMPTY) as $word)<span class="hero-word">{{ $word }}</span> @endforeach<span class="hero-word text-brand-green">{{ $c['highlight'] }}</span></h1>
                @php($categories = $hero['data']['categories'] ?? [])
                @if (count($categories) >= 2)
                    {{-- Screen readers get one fixed sentence. The rotating copy is hidden from them, and its words share one grid cell, so the line never changes size. --}}
                    <div class="motion-scope flex flex-wrap items-center gap-x-5 gap-y-2">
                        <p class="sr-only">Where ideas find talent in {{ \Illuminate\Support\Arr::join($categories, ', ', ' and ') }}.</p>
                        <p aria-hidden="true" class="text-xl font-semibold text-white">Where ideas find talent in <span class="hero-rotor" data-count="{{ count($categories) }}">@foreach ($categories as $category)<span>{{ $category }}</span>@endforeach</span></p>
                        <label for="hero-pause" class="motion-control inline-flex cursor-pointer items-center gap-2 rounded-lg border border-white/60 px-3 py-1.5 text-sm text-white has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-white">
                            <input id="hero-pause" type="checkbox" class="motion-toggle size-4 accent-brand-green"> Pause motion
                        </label>
                    </div>
                @endif
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

    @unless ($hero)
        {{-- The page always has one h1, even when Admin has switched the hero off. --}}
        <h1 class="sr-only">Where Ideas Find People</h1>
    @endunless

    <div class="flex flex-col gap-20">
        @foreach ($body as $section)
            {{-- Fades up as it scrolls into view (CSS only, and only where the browser supports it and motion is allowed). --}}
            <div class="reveal">
                @include('public.home.'.str_replace('_', '-', $section['key']), ['c' => $section['content'], 'd' => $section['data']])
            </div>
        @endforeach
    </div>
</x-layouts.public>
