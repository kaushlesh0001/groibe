<x-layouts.public title="Organizations">
    @push('meta')
        <meta name="description" content="Organizations that work with Groibe and have chosen to be listed.">
        <link rel="canonical" href="{{ route('organizations.index') }}">
        <meta property="og:title" content="Organizations | Groibe">
        <meta property="og:description" content="Organizations that work with Groibe and have chosen to be listed.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('organizations.index') }}">
    @endpush

    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">Organizations</h1>
            <p class="text-zinc-700">Organizations that work with Groibe and have chosen to be listed.</p>
        </div>

        <form method="GET" action="{{ route('organizations.index') }}" class="flex flex-wrap items-end gap-3" role="search">
            <div class="flex flex-col gap-1">
                <label for="org-search" class="text-sm font-medium">Search by name</label>
                <input id="org-search" name="q" type="search" value="{{ $term }}" maxlength="100" class="rounded-lg border border-zinc-300 px-3 py-2">
            </div>
            <button type="submit" class="rounded-lg bg-brand-green px-4 py-2 font-semibold text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-navy">Search</button>
        </form>

        @if ($organizations->isEmpty())
            <p class="text-zinc-700">No organizations are listed yet.</p>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($organizations as $organization)
                    <x-organization-card :organization="$organization" />
                @endforeach
            </ul>

            {{ $organizations->links('pagination::tailwind') }}
        @endif
    </div>
</x-layouts.public>
