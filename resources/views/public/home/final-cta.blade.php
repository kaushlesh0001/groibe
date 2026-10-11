<section aria-labelledby="cta-heading" class="flex flex-col gap-4 rounded-2xl bg-brand-navy p-8 text-white sm:p-12">
    <h2 id="cta-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
    <p class="max-w-2xl text-zinc-200">{{ $c['text'] }}</p>
    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
        <a href="{{ $c['button_url'] ?? route('register.contributor') }}" class="rounded-lg bg-brand-green px-6 py-3 font-semibold text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">{{ $c['button_label'] }}</a>
        <a href="{{ route('register.client') }}" class="rounded-md font-medium underline underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">{{ $c['secondary_label'] }}</a>
    </div>
</section>
