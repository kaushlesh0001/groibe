@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title ? $title.' | '.config('app.name') : config('app.name')])
    </head>
    <body class="flex min-h-screen flex-col bg-white text-brand-navy antialiased">
        <x-impersonation-banner />

        {{-- Navy surfaces carry the white logo; green is the action color, with navy text for contrast (spec 10.3). --}}
        <header class="bg-brand-navy text-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <a href="{{ route('home') }}" class="flex items-center rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                    <x-app-logo variant="white" />
                </a>
                <nav class="flex items-center gap-5 text-sm">
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
            <section class="bg-brand-navy text-white">
                <div class="mx-auto max-w-6xl px-6 pb-20 pt-12 sm:pt-20">
                    {{ $hero }}
                </div>
            </section>
        @endisset

        <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-16">
            {{ $slot }}
        </main>

        <footer class="bg-brand-navy text-white">
            <div class="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-10 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('home') }}" class="rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                    <x-app-logo variant="white" class="h-7" />
                </a>
                <p class="text-sm text-zinc-200">Where Ideas Find People. &copy; {{ now()->year }} Groibe</p>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
