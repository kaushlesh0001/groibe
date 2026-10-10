@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title ? $title.' | '.config('app.name') : config('app.name')])
    </head>
    <body class="min-h-screen bg-white text-navy antialiased">
        <x-impersonation-banner />

        <header class="border-b border-zinc-200">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                    <x-app-logo />
                </a>
                <nav class="flex items-center gap-4 text-sm">
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-brand-blue hover:underline">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="text-brand-blue hover:underline">Log in</a>
                        <a href="{{ route('register.contributor') }}" class="rounded-md bg-brand-green px-4 py-2 font-medium text-navy hover:opacity-90">Join Groibe</a>
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 py-16">
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>
