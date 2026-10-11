@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title ? $title.' | '.config('app.name') : config('app.name')])
    </head>
    <body class="flex min-h-screen flex-col bg-white text-brand-navy antialiased">
        {{-- Lets keyboard and screen reader users jump past the menu. It shows only while it has focus. --}}
        <a href="{{ isset($hero) ? '#hero' : '#main' }}" class="sr-only rounded-lg bg-white px-4 py-2 font-semibold text-brand-navy focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-navy">Skip to content</a>

        <x-impersonation-banner />

        {{-- Navy surfaces carry the white logo; green is the action color, with navy text for contrast (spec 10.3). --}}
        <header class="bg-brand-navy text-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-3 px-6 py-4">
                <a href="{{ route('home') }}" class="flex items-center rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                    <x-app-logo variant="white" />
                </a>
                <nav aria-label="Main" class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    @foreach ($nav['header'] ?? [] as $link)
                        <a href="{{ $link['url'] }}" class="rounded-md text-white hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">{{ $link['label'] }}</a>
                    @endforeach
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-md font-medium text-white hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-md font-medium text-white hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">Log in</a>
                        <a href="{{ route('register.contributor') }}" class="rounded-lg bg-brand-green px-4 py-2 font-semibold text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">Join Groibe</a>
                    @endauth
                </nav>
            </div>
        </header>

        @isset($hero)
            <section id="hero" tabindex="-1" class="bg-brand-navy text-white focus:outline-none">
                <div class="mx-auto max-w-6xl px-6 pb-20 pt-12 sm:pt-20">
                    {{ $hero }}
                </div>
            </section>
        @endisset

        <main id="main" tabindex="-1" class="mx-auto w-full max-w-6xl flex-1 px-6 py-16 focus:outline-none">
            {{ $slot }}
        </main>

        <footer class="bg-brand-navy text-white">
            <div class="mx-auto flex max-w-6xl flex-col gap-6 px-6 py-10">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <a href="{{ route('home') }}" class="rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                        <x-app-logo variant="white" class="h-7" />
                    </a>
                    <p class="text-sm text-zinc-200">Where Ideas Find People. &copy; {{ now()->year }} Groibe</p>
                </div>
                @if (! empty($nav['footer']))
                    <nav aria-label="Footer" class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        @foreach ($nav['footer'] as $link)
                            <a href="{{ $link['url'] }}" class="rounded-md text-zinc-200 hover:text-white hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">{{ $link['label'] }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
