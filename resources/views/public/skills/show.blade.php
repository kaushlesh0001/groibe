<x-layouts.public :title="$skill->name">
    @push('meta')
        <meta name="description" content="Open roles, contributors and proof of work for {{ $skill->name }} on Groibe.">
        <link rel="canonical" href="{{ route('skills.show', $skill->slug) }}">
        <meta property="og:title" content="{{ $skill->name }} | Groibe">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('skills.show', $skill->slug) }}">
        <script type="application/ld+json">
            {!! \App\Support\Seo\JsonLd::json([
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $skill->name, 'item' => route('skills.show', $skill->slug)],
                ],
            ]) !!}
        </script>
    @endpush

    <div class="flex flex-col gap-12">
        <header class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">{{ $skill->name }}</h1>
            @if ($skill->category)
                <p class="text-zinc-700">{{ $skill->category->name }}</p>
            @endif
        </header>

        @if ($roles->isNotEmpty())
            <section aria-labelledby="skill-roles" class="flex flex-col gap-4">
                <h2 id="skill-roles" class="text-xl font-semibold">Open roles</h2>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($roles as $role)
                        <x-open-role-card :role="$role" :href="route('opportunities.show', $role->slug)" />
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($profiles->isNotEmpty())
            <section aria-labelledby="skill-people" class="flex flex-col gap-4">
                <h2 id="skill-people" class="text-xl font-semibold">Contributors</h2>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($profiles as $profile)
                        <x-contributor-card :profile="$profile" />
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($proofs->isNotEmpty())
            <section aria-labelledby="skill-work" class="flex flex-col gap-4">
                <h2 id="skill-work" class="text-xl font-semibold">Proof of work</h2>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($proofs as $proof)
                        <x-proof-card :item="$proof" />
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($roles->isEmpty() && $profiles->isEmpty() && $proofs->isEmpty())
            <p class="text-zinc-700">Nothing is listed for this skill yet.</p>
        @endif
    </div>
</x-layouts.public>
