<x-layouts.public title="Where Ideas Find People">
    <x-slot:hero>
        <div class="flex flex-col gap-6">
            {{-- One highlighted word per headline. Green on navy is 4.65:1; green is never used as text on white (spec 10.3). --}}
            <h1 class="max-w-3xl text-4xl font-bold tracking-tight sm:text-6xl">Where Ideas Find <span class="text-brand-green">People</span></h1>
            <p class="max-w-2xl text-lg text-zinc-200">Groibe assembles and manages teams of skilled professionals to deliver outcomes for growing businesses.</p>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <a href="{{ route('register.contributor') }}" class="rounded-lg bg-brand-green px-6 py-3 font-semibold text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">Join Groibe</a>
                <a href="{{ route('register.client') }}" class="rounded-md font-medium text-white underline underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">Need work delivered? Submit a brief</a>
            </div>
        </div>
    </x-slot:hero>

    <section class="flex flex-col gap-3">
        <h2 class="text-2xl font-bold tracking-tight">One platform. Two sides. One delivery loop.</h2>
        <p class="max-w-2xl text-zinc-600">Clients describe the outcome they need. Groibe scopes it, builds the team, checks the work and delivers it. Contributors do work that counts and build a record they can show.</p>
    </section>
</x-layouts.public>
