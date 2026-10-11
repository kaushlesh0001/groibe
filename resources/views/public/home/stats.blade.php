<section aria-labelledby="stats-heading" class="flex flex-col gap-6">
    <h2 id="stats-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
    <dl class="grid gap-4 sm:grid-cols-3">
        @foreach ($d['tiles'] as $tile)
            <div class="flex flex-col gap-1 rounded-xl border border-zinc-200 p-6">
                <dt class="text-zinc-700">{{ $tile['label'] }}</dt>
                <dd class="text-4xl font-bold">{{ number_format($tile['value']) }}</dd>
            </div>
        @endforeach
    </dl>
</section>
