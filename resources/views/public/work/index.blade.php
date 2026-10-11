<x-layouts.public title="Our work">
    @push('meta')
        <meta name="description" content="Real work delivered by Groibe contributors for client projects, shown with the client's consent.">
        <link rel="canonical" href="{{ route('work.index') }}">
        <meta property="og:title" content="Our work | Groibe">
        <meta property="og:description" content="Real work delivered by Groibe contributors.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('work.index') }}">
    @endpush

    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">Our work</h1>
            <p class="text-zinc-600">Examples of work delivered on Groibe projects, shown with the client's consent.</p>
        </div>

        <form method="GET" action="{{ route('work.index') }}" class="flex flex-wrap items-end gap-4" role="search">
            <div class="flex flex-col gap-1">
                <label for="q" class="text-sm font-medium">Search</label>
                <input id="q" name="q" type="search" value="{{ $term }}" maxlength="100" class="rounded-lg border border-zinc-300 px-3 py-2">
            </div>
            <div class="flex flex-col gap-1">
                <label for="skill" class="text-sm font-medium">Skill</label>
                <select id="skill" name="skill" class="rounded-lg border border-zinc-300 px-3 py-2">
                    <option value="">Any skill</option>
                    @foreach ($skills as $option)
                        <option value="{{ $option->slug }}" @selected($skill === $option->slug)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-lg bg-brand-green px-4 py-2 font-semibold text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-navy">Search</button>
        </form>

        @if ($items->isEmpty())
            <p class="text-zinc-600">Nothing to show here yet.</p>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $item)
                    <x-proof-card :item="$item" />
                @endforeach
            </ul>

            {{ $items->links('pagination::tailwind') }}
        @endif
    </div>
</x-layouts.public>
