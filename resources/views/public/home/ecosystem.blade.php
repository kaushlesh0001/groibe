<section aria-labelledby="ecosystem-heading" class="flex flex-col gap-8">
    <div class="flex flex-col gap-3">
        <h2 id="ecosystem-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
        <p class="max-w-2xl text-zinc-700">{{ $c['subheading'] }}</p>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-6">
            <h3 class="text-lg font-semibold">{{ $c['contributors_title'] }}</h3>
            <p class="text-zinc-700">{{ $c['contributors_text'] }}</p>
            <a href="{{ route('register.contributor') }}" class="w-fit rounded-md text-brand-blue underline underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-navy">Join as a contributor</a>
        </div>
        <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-6">
            <h3 class="text-lg font-semibold">{{ $c['clients_title'] }}</h3>
            <p class="text-zinc-700">{{ $c['clients_text'] }}</p>
            <a href="{{ route('register.client') }}" class="w-fit rounded-md text-brand-blue underline underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-navy">Submit a brief</a>
        </div>
    </div>
</section>
