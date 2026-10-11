<x-layouts.public title="Opportunities">
    @push('meta')
        <meta name="description" content="Open roles on real client projects. Apply, get shortlisted, and build proof of your work with Groibe.">
        <link rel="canonical" href="{{ route('opportunities.index') }}">
        <meta property="og:title" content="Opportunities | Groibe">
        <meta property="og:description" content="Open roles on real client projects.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('opportunities.index') }}">
    @endpush

    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">Opportunities</h1>
            <p class="text-zinc-600">Roles open to skilled professionals. Everyone can browse. You need a Groibe account to apply.</p>
        </div>

        <x-open-role-filters :action="route('opportunities.index')" :filters="$filters" :options="$options" />

        @if ($roles->isEmpty())
            <p class="text-zinc-600">No roles match that search right now.</p>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($roles as $role)
                    <x-open-role-card :role="$role" :href="route('opportunities.show', $role->slug)" />
                @endforeach
            </ul>

            {{ $roles->links('pagination::tailwind') }}
        @endif
    </div>
</x-layouts.public>
