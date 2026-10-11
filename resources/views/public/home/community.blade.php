<section aria-labelledby="community-heading" class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h2 id="community-heading" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $c['heading'] }}</h2>
        @if (! empty($c['intro']))
            <p class="max-w-2xl text-zinc-700">{{ $c['intro'] }}</p>
        @endif
    </div>
    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($d['profiles'] as $profile)
            <x-contributor-card :profile="$profile" />
        @endforeach
    </ul>
    <a href="{{ route('contributors.index') }}" class="w-fit rounded-md font-medium text-brand-blue underline underline-offset-4 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-navy">{{ $c['cta_label'] }}</a>
</section>
