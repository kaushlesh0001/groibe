<section aria-labelledby="faq-heading" class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h2 id="faq-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
        @if (! empty($c['intro']))
            <p class="max-w-2xl text-zinc-700">{{ $c['intro'] }}</p>
        @endif
    </div>

    <x-faq-tabs :groups="$d['groups']" />
</section>
